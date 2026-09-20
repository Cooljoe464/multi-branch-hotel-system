<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\RateOverride;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\YieldRule;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PricingService
{
    private ?Branch $branch = null;

    public function forBranch(Branch $branch): self
    {
        $this->branch = $branch;

        return $this;
    }

    public function getEffectiveRate(RoomType $roomType, Carbon|string $date, ?RatePlan $ratePlan = null): int
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);
        $branch = $this->branch;
        if (! $branch) {
            throw new \LogicException('Branch must be set before calculating rates.');
        }

        $baseRate = $roomType->base_rate;

        if ($ratePlan && $ratePlan->is_active) {
            $dateStr = $date->toDateString();
            if ($dateStr >= $ratePlan->valid_from && ($ratePlan->valid_to === null || $dateStr <= $ratePlan->valid_to)) {
                $baseRate = (int) round($baseRate * $ratePlan->rate_multiplier);
            }
        }

        $override = RateOverride::forBranch($branch->id)
            ->active()
            ->forRoomType($roomType->id)
            ->forDate($date->toDateString())
            ->first();

        if ($override && $override->rate_override !== null) {
            $baseRate = $override->rate_override;
        }

        $occupancyPct = (int) $this->getOccupancyPercentage($roomType, $date);

        $yieldRule = YieldRule::forBranch($branch->id)
            ->active()
            ->forRoomType($roomType->id)
            ->matchingOccupancy($occupancyPct)
            ->orderedByPriority()
            ->first();

        if ($yieldRule) {
            $baseRate = (int) round($baseRate * $yieldRule->rate_multiplier);
        }

        if ($ratePlan) {
            if ($ratePlan->min_rate !== null && $baseRate < $ratePlan->min_rate) {
                $baseRate = $ratePlan->min_rate;
            }
            if ($ratePlan->max_rate !== null && $baseRate > $ratePlan->max_rate) {
                $baseRate = $ratePlan->max_rate;
            }
        }

        return $baseRate;
    }

    /**
     * @return array{mlos: int|null, cta: bool, ctd: bool}
     */
    /** @return array{mlos: int|null, cta: bool, ctd: bool} */
    public function getStayConstraints(RoomType $roomType, Carbon|string $date): array
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);
        $branch = $this->branch;
        if (! $branch) {
            throw new \LogicException('Branch must be set before checking constraints.');
        }

        $override = RateOverride::forBranch($branch->id)
            ->active()
            ->forRoomType($roomType->id)
            ->forDate($date->toDateString())
            ->first();

        $mlos = $override?->mlos;
        $cta = $override->cta ?? false;
        $ctd = $override->ctd ?? false;

        $occupancyPct = (int) $this->getOccupancyPercentage($roomType, $date);

        $yieldRule = YieldRule::forBranch($branch->id)
            ->active()
            ->forRoomType($roomType->id)
            ->matchingOccupancy($occupancyPct)
            ->orderedByPriority()
            ->first();

        if ($yieldRule) {
            $mlos = $yieldRule->mlos_override ?? $mlos;
            $cta = $yieldRule->cta_override ?? $cta;
        }

        return [
            'mlos' => $mlos,
            'cta' => $cta,
            'ctd' => $ctd,
        ];
    }

    /**
     * @return array{total: int, nights: int, per_night: list<array{date: string, rate: int}>, mlos: int|null, mlos_met: bool, cta: bool, cta_violated: bool, ctd: bool}
     */
    public function calculateTotal(RoomType $roomType, Carbon|string $checkIn, Carbon|string $checkOut, ?RatePlan $ratePlan = null): array
    {
        $checkIn = $checkIn instanceof Carbon ? $checkIn : Carbon::parse($checkIn);
        $checkOut = $checkOut instanceof Carbon ? $checkOut : Carbon::parse($checkOut);
        $nights = (int) $checkIn->diffInDays($checkOut);
        $perNight = [];
        $total = 0;

        $current = $checkIn->copy();
        for ($i = 0; $i < $nights; $i++) {
            $rate = $this->getEffectiveRate($roomType, $current, $ratePlan);
            $perNight[] = [
                'date' => $current->toDateString(),
                'rate' => $rate,
            ];
            $total += $rate;
            $current->addDay();
        }

        $checkInConstraints = $this->getStayConstraints($roomType, $checkIn);
        $mlosMet = $checkInConstraints['mlos'] === null || $nights >= $checkInConstraints['mlos'];
        $ctaViolated = $checkInConstraints['cta'] === true;

        return [
            'total' => $total,
            'nights' => $nights,
            'per_night' => $perNight,
            'mlos' => $checkInConstraints['mlos'],
            'mlos_met' => $mlosMet,
            'cta' => $checkInConstraints['cta'],
            'cta_violated' => $ctaViolated,
            'ctd' => $checkInConstraints['ctd'],
        ];
    }

    public function getOccupancyPercentage(?RoomType $roomType = null, Carbon|string|null $date = null): float
    {
        $branch = $this->branch;
        if (! $branch) {
            return 0.0;
        }

        $occupancyService = new OccupancyService;

        return $occupancyService->getPercentage($branch->id, $roomType, $date);
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getAvailableRoomTypesForSearch(int $branchId, string $checkIn, string $checkOut, int $adults): Collection
    {
        $branch = Branch::findOrFail($branchId);
        $this->forBranch($branch);

        $checkInDate = Carbon::parse($checkIn);
        $checkOutDate = Carbon::parse($checkOut);
        $nights = $checkInDate->diffInDays($checkOutDate);

        $allRoomTypes = RoomType::forBranch($branchId)
            ->active()
            ->where('max_occupancy', '>=', $adults)
            ->withCount([
                'rooms as available_count' => function ($q) use ($checkIn, $checkOut) {
                    /** @var Builder<Room> $q */
                    $q->where('is_active', true)
                        ->where('status', '!=', 'out_of_order')
                        ->whereDoesntHave('reservations', function ($rQ) use ($checkIn, $checkOut) {
                            /** @var Builder<Reservation> $rQ */
                            $rQ->whereIn('status', ['confirmed', 'reserved', 'checked_in'])
                                ->where('check_in_date', '<', $checkOut)
                                ->where('check_out_date', '>', $checkIn);
                        });
                },
            ])
            ->get();

        /** @var list<array<string, mixed>> $items */
        $items = [];

        foreach ($allRoomTypes->filter(fn (RoomType $rt) => $rt->available_count > 0) as $rt) {
            /** @var list<array{date: string, rate: int}> $perNight */
            $perNight = [];
            $total = 0;
            $current = $checkInDate->copy();

            for ($i = 0; $i < $nights; $i++) {
                $rate = $this->getEffectiveRate($rt, $current);
                $perNight[] = [
                    'date' => $current->toDateString(),
                    'rate' => $rate,
                ];
                $total += $rate;
                $current->addDay();
            }

            $constraints = $this->getStayConstraints($rt, $checkInDate);

            $items[] = [
                'room_type_id' => $rt->id,
                'name' => $rt->name,
                'code' => $rt->code,
                'description' => $rt->description,
                'max_occupancy' => $rt->max_occupancy,
                'bed_count' => $rt->bed_count,
                'bed_type' => $rt->bed_type,
                'amenities' => $rt->amenities,
                'available_count' => $rt->available_count,
                'base_rate' => $rt->base_rate,
                'effective_rate' => $perNight[0]['rate'] ?? $rt->base_rate,
                'total_rate' => $total,
                'per_night' => $perNight,
                'mlos' => $constraints['mlos'],
                'cta' => $constraints['cta'],
                'ctd' => $constraints['ctd'],
            ];
        }

        return collect($items);
    }
}
