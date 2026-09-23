<?php

namespace App\Services;

use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\FxRate;
use App\Support\BranchTime;

/**
 * FX reference rates and exact integer conversion. Rates are daily
 * rows in micros (1 base = rate_micro / 1_000_000 quote); lookups
 * take the latest row on or before the date so a missing today
 * falls back to yesterday instead of failing. Same-currency
 * converts are identity and need no row. Only explicit pairs
 * convert — no reciprocal inference, so rounding stays exact.
 */
class FxService
{
    public const MICROS = 1000000;

    public function setRate(string $base, string $quote, string $date, int $rateMicro, string $source = 'manual'): FxRate
    {
        if ($rateMicro <= 0) {
            throw new AvailabilityException('FX_RATE_INVALID', 'FX rates must be positive.');
        }

        return FxRate::updateOrCreate(
            [
                'base_code' => strtoupper($base),
                'quote_code' => strtoupper($quote),
                'rate_date' => $date,
            ],
            ['rate_micro' => $rateMicro, 'source' => $source],
        );
    }

    public function rateFor(string $base, string $quote, string $date): int
    {
        $base = strtoupper($base);
        $quote = strtoupper($quote);

        if ($base === $quote) {
            return self::MICROS;
        }

        $row = FxRate::forPair($base, $quote)->onOrBefore($date)->orderByDesc('rate_date')->first();

        if (! $row) {
            throw new AvailabilityException('FX_RATE_MISSING', "No {$base}/{$quote} rate on or before {$date}.");
        }

        return $row->rate_micro;
    }

    /**
     * Convert integer minor units. Exact: the quotient path never
     * touches floats, the remainder rounds half-up.
     */
    public function convert(int $amountMinor, string $from, string $to, string $date): int
    {
        $rateMicro = $this->rateFor($from, $to, $date);

        if (strtoupper($from) === strtoupper($to)) {
            return $amountMinor;
        }

        $quotient = intdiv($amountMinor, self::MICROS);
        $remainder = $amountMinor % self::MICROS;

        return $quotient * $rateMicro + intdiv($remainder * $rateMicro + (int) (self::MICROS / 2), self::MICROS);
    }

    public function convertForBranch(int $amountMinor, Branch $branch, string $to, ?string $date = null): int
    {
        return $this->convert($amountMinor, $branch->currency_code, $to, $date ?? BranchTime::today($branch));
    }
}
