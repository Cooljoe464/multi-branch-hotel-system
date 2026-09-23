<?php

namespace App\Services;

use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\CorporateAccount;
use App\Models\PromoCode;
use App\Models\RatePlan;
use App\Models\RateSeason;
use App\Models\RoomType;
use App\Support\BranchTime;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Rate plan depth pricing: seasons, derived plans, packages, promo codes
 * and corporate discounts. Every amount is an integer in minor units;
 * percentage math goes through mulDiv() so totals are exact and the
 * frozen snapshot always reconciles with posted folio lines.
 *
 * price() is side-effect free (quotes). consumePromo() performs the
 * locked redemption increment and must run inside the booking
 * transaction.
 *
 * @phpstan-type RateComponent array{code: string, label: string, amount_minor: int, category: string}
 * @phpstan-type RateNight array{date: string, base_minor: int, season_code: string|null, season_multiplier_bps: int, derived_minor: int, promo_discount_minor: int, corporate_discount_minor: int, total_minor: int, components: list<RateComponent>}
 * @phpstan-type RateQuote array{plan_id: int, plan_code: string, currency: string, nights: list<RateNight>, subtotal_minor: int, promo_discount_minor: int, corporate_discount_minor: int, total_minor: int, promo_code_id: int|null, promo_code: string|null, corporate_account_id: int|null, corporate_code: string|null, quoted_at: string}
 */
class RateEngine
{
    /**
     * @return RateQuote
     *
     * @throws AvailabilityException
     */
    public function price(
        Branch $branch,
        RatePlan $plan,
        RoomType $roomType,
        string $checkIn,
        string $checkOut,
        ?PromoCode $promo = null,
        ?CorporateAccount $corporate = null,
        ?string $bookingDate = null,
        int $depth = 0,
    ): array {
        if ($depth > 5) {
            throw new AvailabilityException('RATE_DERIVATION_TOO_DEEP', 'Rate plan derivation exceeds five levels.');
        }

        if ($plan->branch_id !== $branch->id || $roomType->branch_id !== $branch->id) {
            throw new AvailabilityException('RATE_BRANCH_MISMATCH', 'The rate plan and room type must belong to this property.');
        }

        if (! $plan->is_active) {
            throw new AvailabilityException('RATE_PLAN_INACTIVE', 'The selected rate plan is not active.');
        }

        $nights = (new AvailabilityService)->nights($checkIn, $checkOut);
        $bookingDate ??= BranchTime::today($branch);

        $this->guardPromo($promo, $branch, $bookingDate, count($nights));
        $this->guardCorporate($corporate, $branch);

        $seasons = RateSeason::forBranch($branch->id)->active()
            ->orderByDesc('priority')->orderBy('id')->get();

        $quoted = [];
        $subtotal = 0;
        $promoTotal = 0;
        $corporateTotal = 0;

        foreach ($nights as $date) {
            $season = $this->seasonRate($seasons, $date);

            $base = $roomType->base_rate;
            $seasonal = self::mulDiv($base, $season['multiplier_bps']);

            $derived = $plan->base_plan_id !== null
                ? $this->baseNightly($branch, $plan, $roomType, $date, $depth)
                : (int) round($seasonal * $plan->rate_multiplier);

            $derived = $this->applyDerivation($derived, $plan);
            $derived = $this->clamp($derived, $plan);

            $components = $this->componentsFor($plan, $derived);

            $promoCut = $promo !== null ? $this->promoCut($promo, $derived) : 0;
            $afterPromo = $derived - $promoCut;
            $corporateCut = $corporate !== null && $corporate->discount_bps > 0
                ? self::mulDiv($afterPromo, $corporate->discount_bps)
                : 0;

            $nightTotal = max(0, $afterPromo - $corporateCut);
            $components = $this->spreadTotal($components, $nightTotal);

            $quoted[] = [
                'date' => $date,
                'base_minor' => $base,
                'season_code' => $season['code'],
                'season_multiplier_bps' => $season['multiplier_bps'],
                'derived_minor' => $derived,
                'promo_discount_minor' => $promoCut,
                'corporate_discount_minor' => $corporateCut,
                'total_minor' => $nightTotal,
                'components' => $components,
            ];

            $subtotal += $derived;
            $promoTotal += $promoCut;
            $corporateTotal += $corporateCut;
        }

        return [
            'plan_id' => $plan->id,
            'plan_code' => $plan->code,
            'currency' => $branch->currency_code,
            'nights' => $quoted,
            'subtotal_minor' => $subtotal,
            'promo_discount_minor' => $promoTotal,
            'corporate_discount_minor' => $corporateTotal,
            'total_minor' => $subtotal - $promoTotal - $corporateTotal,
            'promo_code_id' => $promo?->id,
            'promo_code' => $promo?->code,
            'corporate_account_id' => $corporate?->id,
            'corporate_code' => $corporate?->code,
            'quoted_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    /**
     * Locked promo redemption. Call inside the booking transaction after
     * price(); throws when the cap was reached concurrently.
     *
     * @throws AvailabilityException
     */
    public function consumePromo(PromoCode $promo): void
    {
        $locked = PromoCode::where('id', $promo->id)->lockForUpdate()->firstOrFail();

        if (! $locked->is_active) {
            throw new AvailabilityException('PROMO_INACTIVE', "Promo code {$locked->code} is not active.");
        }

        if ($locked->exhausted()) {
            throw new AvailabilityException('PROMO_EXHAUSTED', "Promo code {$locked->code} has reached its redemption limit.");
        }

        $locked->increment('uses_count');
    }

    /**
     * Integer percentage: round half-up, exact, no floats in totals.
     */
    public static function mulDiv(int $amount, int $bps): int
    {
        return (int) round($amount * $bps / 10000);
    }

    /**
     * Raw JSON numerics stay integers only when they already are;
     * anything else falls back instead of casting (a cast on mixed
     * would silently hide malformed plan data).
     */
    private static function rawInt(mixed $value, int $default = 0): int
    {
        return is_int($value) ? $value : $default;
    }

    private static function rawString(mixed $value, string $default): string
    {
        return is_string($value) ? $value : $default;
    }

    /**
     * Nightly amount of the base plan (season + multiplier + derivation,
     * no promo/corporate stacking — those apply once at the outer plan).
     *
     * @throws AvailabilityException
     */
    private function baseNightly(Branch $branch, RatePlan $plan, RoomType $roomType, string $date, int $depth): int
    {
        if ($plan->base_plan_id === $plan->id) {
            throw new AvailabilityException('RATE_DERIVATION_CYCLE', 'A rate plan cannot derive from itself.');
        }

        $base = RatePlan::where('id', $plan->base_plan_id)
            ->where('branch_id', $branch->id)
            ->active()
            ->first();

        if (! $base) {
            throw new AvailabilityException('RATE_BASE_PLAN_MISSING', 'The derived rate plan has no active base plan.');
        }

        $next = Carbon::parse($date)->addDay()->toDateString();
        $quote = $this->price($branch, $base, $roomType, $date, $next, null, null, null, $depth + 1);

        return $quote['total_minor'];
    }

    private function applyDerivation(int $amount, RatePlan $plan): int
    {
        if ($plan->derivation_bps !== null) {
            $amount += self::mulDiv($amount, $plan->derivation_bps);
        }

        if ($plan->derivation_fixed_minor !== null) {
            $amount += $plan->derivation_fixed_minor;
        }

        return max(0, $amount);
    }

    private function clamp(int $amount, RatePlan $plan): int
    {
        if ($plan->min_rate !== null) {
            $amount = max($amount, $plan->min_rate);
        }

        if ($plan->max_rate !== null) {
            $amount = min($amount, $plan->max_rate);
        }

        return $amount;
    }

    /**
     * Fixed package components plus a room remainder line, so the
     * components always sum exactly to the nightly amount. Oversized
     * fixed parts scale down pro-rata instead of inflating the total.
     *
     * @return list<RateComponent>
     */
    private function componentsFor(RatePlan $plan, int $nightly): array
    {
        $raw = $plan->package_components ?? [];

        if ($raw === []) {
            return [$this->roomComponent('room', 'Room', $nightly)];
        }

        $fixed = [];
        foreach ($raw as $row) {
            $fixed[] = [
                'code' => self::rawString($row['code'] ?? 'extra', 'extra'),
                'label' => self::rawString($row['label'] ?? 'Package extra', 'Package extra'),
                'amount_minor' => self::rawInt($row['amount_minor'] ?? 0),
                'category' => self::rawString($row['category'] ?? 'package_extra', 'package_extra'),
            ];
        }

        $fixedSum = array_sum(array_column($fixed, 'amount_minor'));

        if ($fixedSum > $nightly && $fixedSum > 0) {
            $scaled = [];
            $scaledSum = 0;
            foreach ($fixed as $component) {
                $amount = (int) floor($component['amount_minor'] * $nightly / $fixedSum);
                $scaled[] = [
                    'code' => $component['code'],
                    'label' => $component['label'],
                    'amount_minor' => $amount,
                    'category' => $component['category'],
                ];
                $scaledSum += $amount;
            }
            $fixed = $scaled;
            $fixedSum = $scaledSum;
        }

        $components = [$this->roomComponent('room', 'Room', $nightly - $fixedSum)];

        foreach ($fixed as $component) {
            $components[] = $component;
        }

        return $components;
    }

    /**
     * @param  list<RateComponent>  $components
     * @return list<RateComponent>
     */
    private function spreadTotal(array $components, int $total): array
    {
        $sum = array_sum(array_column($components, 'amount_minor'));

        if ($sum === $total) {
            return $components;
        }

        if ($sum === 0) {
            $spread = [];
            foreach ($components as $index => $component) {
                $spread[] = [
                    'code' => $component['code'],
                    'label' => $component['label'],
                    'amount_minor' => $index === 0 ? $total : 0,
                    'category' => $component['category'],
                ];
            }

            return $spread;
        }

        $amounts = [];
        foreach ($components as $component) {
            $amounts[] = (int) floor($component['amount_minor'] * $total / $sum);
        }

        $dust = $total - array_sum($amounts);

        $spread = [];
        foreach ($components as $index => $component) {
            $spread[] = [
                'code' => $component['code'],
                'label' => $component['label'],
                // Rounding dust lands on the room (first) line so sums stay exact.
                'amount_minor' => $amounts[$index] + ($index === 0 ? $dust : 0),
                'category' => $component['category'],
            ];
        }

        return $spread;
    }

    /**
     * @return RateComponent
     */
    private function roomComponent(string $code, string $label, int $amount): array
    {
        return ['code' => $code, 'label' => $label, 'amount_minor' => $amount, 'category' => 'room_rate'];
    }

    /**
     * Season match for one date, defaulting to the plain base rate.
     * Returns a plain array (never null) so callers do no nullable
     * chaining over the match result.
     *
     * @param  Collection<int, RateSeason>  $seasons
     * @return array{code: string|null, multiplier_bps: int}
     */
    private function seasonRate(Collection $seasons, string $date): array
    {
        $parsed = Carbon::parse($date);

        foreach ($seasons as $season) {
            if ($season->contains($parsed->month, $parsed->day)) {
                return ['code' => $season->code, 'multiplier_bps' => $season->multiplier_bps];
            }
        }

        return ['code' => null, 'multiplier_bps' => 10000];
    }

    private function promoCut(PromoCode $promo, int $nightly): int
    {
        $cut = 0;

        if ($promo->discount_bps !== null) {
            $cut += self::mulDiv($nightly, $promo->discount_bps);
        }

        if ($promo->discount_fixed_minor !== null) {
            $cut += $promo->discount_fixed_minor;
        }

        return min($cut, $nightly);
    }

    /**
     * @throws AvailabilityException
     */
    private function guardPromo(?PromoCode $promo, Branch $branch, string $bookingDate, int $nights): void
    {
        if ($promo === null) {
            return;
        }

        if ($promo->branch_id !== $branch->id) {
            throw new AvailabilityException('PROMO_BRANCH_MISMATCH', 'The promo code does not belong to this property.');
        }

        if (! $promo->is_active) {
            throw new AvailabilityException('PROMO_INACTIVE', "Promo code {$promo->code} is not active.");
        }

        if ($bookingDate < $promo->valid_from->toDateString()
            || ($promo->valid_to !== null && $bookingDate > $promo->valid_to->toDateString())) {
            throw new AvailabilityException('PROMO_EXPIRED', "Promo code {$promo->code} is not valid for this booking date.");
        }

        if ($nights < $promo->min_nights) {
            throw new AvailabilityException('PROMO_MIN_NIGHTS', "Promo code {$promo->code} requires at least {$promo->min_nights} nights.");
        }

        if ($promo->exhausted()) {
            throw new AvailabilityException('PROMO_EXHAUSTED', "Promo code {$promo->code} has reached its redemption limit.");
        }
    }

    /**
     * @throws AvailabilityException
     */
    private function guardCorporate(?CorporateAccount $corporate, Branch $branch): void
    {
        if ($corporate === null) {
            return;
        }

        if ($corporate->branch_id !== $branch->id) {
            throw new AvailabilityException('CORPORATE_BRANCH_MISMATCH', 'The corporate account does not belong to this property.');
        }

        if (! $corporate->is_active) {
            throw new AvailabilityException('CORPORATE_INACTIVE', "Corporate account {$corporate->code} is not active.");
        }
    }
}
