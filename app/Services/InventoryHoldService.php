<?php

namespace App\Services;

use App\Exceptions\AvailabilityException;
use App\Models\ApiKey;
use App\Models\Branch;
use App\Models\InventoryHold;
use App\Models\PublicApiQuote;
use App\Models\RoomType;
use App\Models\RoomTypeInventory;
use Illuminate\Support\Facades\DB;

class InventoryHoldService
{
    public function create(
        ApiKey $apiKey,
        Branch $branch,
        RoomType $roomType,
        string $checkIn,
        string $checkOut,
        string $sessionId,
        string $clientIp,
        PublicApiQuote $quote,
        ?int $ttlMinutes = null,
    ): InventoryHold {
        $ttlMinutes ??= config()->integer('public_api.hold_ttl_minutes', 10);
        $ttlMinutes = max(5, min($ttlMinutes, 30));

        return DB::transaction(function () use ($apiKey, $branch, $roomType, $checkIn, $checkOut, $sessionId, $clientIp, $quote, $ttlMinutes): InventoryHold {
            // Serialize cap checks and inventory changes for this property so
            // concurrent requests cannot pass the same per-IP/session limit.
            Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $apiKey = ApiKey::query()->whereKey($apiKey->id)->lockForUpdate()->firstOrFail();
            $lockedQuote = PublicApiQuote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if (! $lockedQuote->usable()
                || $lockedQuote->api_key_id !== $apiKey->id
                || $lockedQuote->branch_id !== $branch->id
                || $lockedQuote->room_type_id !== $roomType->id
                || $lockedQuote->check_in !== $checkIn
                || $lockedQuote->check_out !== $checkOut) {
                throw new AvailabilityException('QUOTE_INVALID', 'The quote has expired, was used, or does not match this booking.');
            }

            $nights = (new AvailabilityService)->nights($checkIn, $checkOut);
            $availability = new AvailabilityService;

            $this->assertCap($branch, $apiKey, 'key', 'api_key_id', $apiKey->id, $apiKey->hold_limit_per_key);
            $this->assertCap($branch, $apiKey, 'ip', 'client_ip', $clientIp, $apiKey->hold_limit_per_ip);
            $this->assertCap($branch, $apiKey, 'session', 'session_id', $sessionId, $apiKey->hold_limit_per_session);

            $rows = [];
            foreach ($nights as $date) {
                $row = RoomTypeInventory::query()
                    ->where('room_type_id', $roomType->id)
                    ->whereDate('stay_date', $date)
                    ->lockForUpdate()
                    ->first();

                if (! $row) {
                    $row = new RoomTypeInventory([
                        'branch_id' => $branch->id,
                        'room_type_id' => $roomType->id,
                        'stay_date' => $date,
                        'total_rooms' => $roomType->rooms()->count(),
                        'sold' => 0,
                        'blocked' => 0,
                        'held' => 0,
                        'overbooking_limit' => 0,
                    ]);
                    $row->save();
                    $row = RoomTypeInventory::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
                }

                if ($availability->sellableFor($branch, $roomType, $date, 'public_api') < 1) {
                    throw new AvailabilityException('HOLD_UNAVAILABLE', 'Inventory is not available for one or more requested nights.');
                }

                $rows[] = $row;
            }

            foreach ($rows as $row) {
                $row->increment('held');
            }

            $hold = InventoryHold::create([
                'tenant_id' => $branch->tenant_id,
                'branch_id' => $branch->id,
                'room_type_id' => $roomType->id,
                'api_key_id' => $apiKey->id,
                'public_api_quote_id' => $lockedQuote->id,
                'source' => 'public_api',
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'status' => 'active',
                'session_id' => $sessionId,
                'client_ip' => $clientIp,
                'expires_at' => now()->addMinutes($ttlMinutes),
            ]);

            $lockedQuote->update(['consumed_at' => now()]);

            return $hold;
        }, 3);
    }

    public function release(InventoryHold $hold): InventoryHold
    {
        return DB::transaction(function () use ($hold): InventoryHold {
            $locked = InventoryHold::query()->whereKey($hold->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'active') {
                return $locked;
            }

            foreach ((new AvailabilityService)->nights($locked->check_in->toDateString(), $locked->check_out->toDateString()) as $date) {
                RoomTypeInventory::query()
                    ->where('room_type_id', $locked->room_type_id)
                    ->whereDate('stay_date', $date)
                    ->lockForUpdate()
                    ->decrement('held');
            }

            $locked->update(['status' => 'released', 'released_at' => now()]);

            return $locked->fresh() ?? $locked;
        }, 3);
    }

    public function releaseExpired(): int
    {
        $released = 0;

        InventoryHold::query()
            ->where('status', 'active')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($holds) use (&$released): void {
                foreach ($holds as $hold) {
                    $this->release($hold);
                    $released++;

                    // Expired holds are the only automated release path; notify
                    // connected booking-engine consumers so they can stop holding.
                    app(WebhookDispatcher::class)->dispatch('hold.expired', [
                        'hold_id' => $hold->id,
                        'quote_id' => $hold->public_api_quote_id,
                        'room_type_id' => $hold->room_type_id,
                        'check_in' => $hold->check_in?->toDateString(),
                        'check_out' => $hold->check_out?->toDateString(),
                        'api_key_id' => $hold->api_key_id,
                        'session_id' => $hold->session_id,
                    ], $hold->branch_id);
                }
            });

        return $released;
    }

    private function assertCap(Branch $branch, ApiKey $apiKey, string $scope, string $column, int|string $value, int $limit): void
    {
        $count = InventoryHold::query()
            ->where('branch_id', $branch->id)
            ->when($scope === 'key', fn ($query) => $query->where('api_key_id', $apiKey->id))
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->where($column, $value)
            ->count();

        if ($count >= $limit) {
            throw new AvailabilityException('HOLD_CAP_EXCEEDED', 'The inventory hold limit has been reached.');
        }
    }
}
