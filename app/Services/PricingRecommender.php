<?php

namespace App\Services;

use App\Events\PriceApplied;
use App\Events\PriceProposed;
use App\Events\RestrictionsChanged;
use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\DemandForecast;
use App\Models\PriceRecommendation;
use App\Models\RateOverride;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Forecasting\Forecaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Demand-driven price proposals with hard guardrails. The model
 * proposes; humans dispose — application writes a date-specific
 * rate override only on approval (or inside the ±5% auto-apply
 * band when the branch opts in), and double-apply collapses to
 * the single existing override.
 */
class PricingRecommender
{
    public const MAX_UPLIFT_BPS = 2500;

    public const AUTO_APPLY_BPS = 500;

    public function propose(Branch $branch, RoomType $roomType, string $stayDate, ?Forecaster $driver = null): PriceRecommendation
    {
        abort_unless($roomType->branch_id === $branch->id, 404);

        $forecast = (new ForecastingService)->generate($branch, $stayDate, $driver);
        $current = $roomType->base_rate;

        $recommended = $this->guardrailed($roomType, $current, $forecast->p_demand);

        $recommendation = PriceRecommendation::create([
            'branch_id' => $branch->id,
            'room_type_id' => $roomType->id,
            'stay_date' => $stayDate,
            'recommended_minor' => $recommended,
            'current_minor' => $current,
            'status' => PriceRecommendation::STATUS_PROPOSED,
        ]);

        event(new PriceProposed($recommendation));

        if ($this->autoApply($branch, $recommendation)) {
            return $this->apply($recommendation, null);
        }

        return $recommendation->fresh() ?? $recommendation;
    }

    public function approve(PriceRecommendation $recommendation, User $decidedBy): PriceRecommendation
    {
        if ($recommendation->status !== PriceRecommendation::STATUS_PROPOSED) {
            return $recommendation;
        }

        $recommendation->update([
            'status' => PriceRecommendation::STATUS_APPROVED,
            'decided_by' => $decidedBy->id,
        ]);

        return $recommendation->fresh() ?? $recommendation;
    }

    public function reject(PriceRecommendation $recommendation, User $decidedBy): PriceRecommendation
    {
        if ($recommendation->status !== PriceRecommendation::STATUS_PROPOSED) {
            return $recommendation;
        }

        $recommendation->update([
            'status' => PriceRecommendation::STATUS_REJECTED,
            'decided_by' => $decidedBy->id,
        ]);

        return $recommendation->fresh() ?? $recommendation;
    }

    public function apply(PriceRecommendation $recommendation, ?User $appliedBy): PriceRecommendation
    {
        if ($recommendation->status === PriceRecommendation::STATUS_APPLIED) {
            return $recommendation;
        }

        if (! in_array($recommendation->status, [PriceRecommendation::STATUS_APPROVED, PriceRecommendation::STATUS_PROPOSED], true)) {
            throw new AvailabilityException('PRICE_STATE', 'Only approved proposals can be applied.');
        }

        if ($appliedBy === null && ! $this->autoApply($recommendation->branch, $recommendation)) {
            throw new AvailabilityException('PRICE_APPROVAL', 'Human approval is required outside the auto-apply band.');
        }

        $this->assertFreshForecast($recommendation);

        return DB::transaction(function () use ($recommendation, $appliedBy) {
            $locked = PriceRecommendation::where('id', $recommendation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === PriceRecommendation::STATUS_APPLIED) {
                return $locked;
            }

            $existing = RateOverride::forBranch($locked->branch_id)
                ->where('room_type_id', $locked->room_type_id)
                ->where('start_date', '<=', $locked->stay_date->toDateString())
                ->where('end_date', '>=', $locked->stay_date->toDateString())
                ->where('rate_override', $locked->recommended_minor)
                ->where('is_active', true)
                ->first();

            if (! $existing) {
                $existing = RateOverride::create([
                    'branch_id' => $locked->branch_id,
                    'room_type_id' => $locked->room_type_id,
                    'start_date' => $locked->stay_date->toDateString(),
                    'end_date' => $locked->stay_date->toDateString(),
                    'rate_override' => $locked->recommended_minor,
                    'is_active' => true,
                    'notes' => "AI price {$locked->id} (human-approved).",
                ]);
            }

            $locked->update([
                'status' => PriceRecommendation::STATUS_APPLIED,
                'decided_by' => $appliedBy instanceof User ? $appliedBy->id : $locked->decided_by,
            ]);

            event(new RestrictionsChanged($locked->branch));
            event(new PriceApplied($locked->fresh() ?? $locked));

            Log::info('AI price applied.', [
                'recommendation_id' => $locked->id,
                'override_id' => $existing->id,
                'by' => $appliedBy instanceof User ? $appliedBy->id : null,
            ]);

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Occupancy-driven target clipped to ±25% and floored per room
     * type, rounded to clean hundreds.
     */
    public function guardrailed(RoomType $roomType, int $current, float $pDemand): int
    {
        $upliftBps = match (true) {
            $pDemand >= 0.85 => 2000,
            $pDemand >= 0.70 => 1000,
            $pDemand <= 0.25 => -2500,
            $pDemand <= 0.40 => -1500,
            default => 0,
        };

        $upliftBps = max(-self::MAX_UPLIFT_BPS, min(self::MAX_UPLIFT_BPS, $upliftBps));

        $target = (int) round($current * (1 + $upliftBps / 10000) / 100) * 100;
        $floor = $roomType->floor_minor ?? (int) round($current / 2);

        return max($floor, $target);
    }

    private function autoApply(Branch $branch, PriceRecommendation $recommendation): bool
    {
        $settings = $branch->settings;

        if (! is_array($settings) || ($settings['ai_price_auto_apply'] ?? false) !== true) {
            return false;
        }

        return abs($recommendation->deviationBps()) <= self::AUTO_APPLY_BPS;
    }

    private function assertFreshForecast(PriceRecommendation $recommendation): void
    {
        $forecast = DemandForecast::forBranch($recommendation->branch_id)
            ->where('stay_date', $recommendation->stay_date->toDateString())
            ->orderByDesc('generated_on')
            ->first();

        if (! $forecast || $forecast->stale()) {
            $recommendation->update(['status' => PriceRecommendation::STATUS_EXPIRED]);

            throw new AvailabilityException('FORECAST_STALE', 'The forecast behind this proposal expired; regenerate it first.');
        }
    }
}
