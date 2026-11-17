<?php

namespace App\Services;

use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\OverbookingAllowance;
use App\Models\RoomType;
use App\Models\RoomTypeInventory;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OverbookingAllowanceService
{
    private const SOURCES = ['front_desk', 'public_api', 'ota'];

    /**
     * @param  list<string>  $allowedSources
     */
    public function create(
        Branch $branch,
        RoomType $roomType,
        string $startsOn,
        string $endsOn,
        int $allowedCount,
        array $allowedSources,
        string $reason,
        User $actor,
    ): OverbookingAllowance {
        $this->authorizeManager($actor);

        if ($roomType->branch_id !== $branch->id || $roomType->is_active !== true) {
            throw new AvailabilityException('OVERBOOK_ROOM_TYPE_INVALID', 'The room type is not active at this property.');
        }

        if ($allowedCount < 1 || trim($reason) === '' || $allowedSources === []
            || array_diff($allowedSources, self::SOURCES) !== []) {
            throw new AvailabilityException('OVERBOOK_ALLOWANCE_INVALID', 'The allowance, source list, and audit reason must be valid.');
        }

        $start = Carbon::createFromFormat('!Y-m-d', $startsOn);
        $end = Carbon::createFromFormat('!Y-m-d', $endsOn);
        if ($start === null || $end === null || $end->lt($start) || $start->lt(now()->startOfDay())) {
            throw new AvailabilityException('OVERBOOK_DATES_INVALID', 'The allowance date range must be valid and begin today or later.');
        }

        return DB::transaction(function () use ($branch, $roomType, $startsOn, $endsOn, $allowedCount, $allowedSources, $reason, $actor): OverbookingAllowance {
            Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
            RoomType::query()->whereKey($roomType->id)->lockForUpdate()->firstOrFail();

            $overlap = OverbookingAllowance::query()
                ->where('branch_id', $branch->id)
                ->where('room_type_id', $roomType->id)
                ->whereNull('revoked_at')
                ->whereDate('starts_on', '<=', $endsOn)
                ->whereDate('ends_on', '>=', $startsOn)
                ->exists();

            if ($overlap) {
                throw new AvailabilityException('OVERBOOK_ALLOWANCE_OVERLAP', 'An active allowance already covers some of these room-type dates.');
            }

            return OverbookingAllowance::create([
                'tenant_id' => $branch->tenant_id,
                'branch_id' => $branch->id,
                'room_type_id' => $roomType->id,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'allowed_count' => $allowedCount,
                'allowed_sources' => array_values(array_unique($allowedSources)),
                'reason' => trim($reason),
                'created_by' => $actor->id,
            ]);
        }, 3);
    }

    public function revoke(OverbookingAllowance $allowance, User $actor): OverbookingAllowance
    {
        $this->authorizeManager($actor);

        return DB::transaction(function () use ($allowance, $actor): OverbookingAllowance {
            $locked = OverbookingAllowance::query()->whereKey($allowance->id)->lockForUpdate()->firstOrFail();

            if ($locked->revoked_at !== null) {
                return $locked;
            }

            $overallocatedDates = RoomTypeInventory::query()
                ->where('branch_id', $locked->branch_id)
                ->where('room_type_id', $locked->room_type_id)
                ->whereDate('stay_date', '>=', $locked->starts_on->toDateString())
                ->whereDate('stay_date', '<=', $locked->ends_on->toDateString())
                ->whereRaw('(sold + blocked + held) > total_rooms')
                ->exists();

            if ($overallocatedDates) {
                throw new AvailabilityException('OVERBOOK_ALLOWANCE_IN_USE', 'This allowance cannot be revoked while allocations exceed physical capacity.');
            }

            $locked->update(['revoked_at' => now(), 'revoked_by' => $actor->id]);

            return $locked->fresh() ?? $locked;
        }, 3);
    }

    private function authorizeManager(User $actor): void
    {
        if (! $actor->can('availability.manage_overbook')) {
            throw new AvailabilityException('OVERBOOK_PERMISSION_REQUIRED', 'Managing overbooking allowances requires the dedicated inventory permission.');
        }

        if (! $actor->hasEnabledTwoFactorAuthentication()) {
            throw new AvailabilityException('OVERBOOK_MFA_REQUIRED', 'Confirmed two-factor authentication is required to manage overbooking allowances.');
        }
    }
}
