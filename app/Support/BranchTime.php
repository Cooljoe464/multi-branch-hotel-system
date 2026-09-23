<?php

namespace App\Support;

use App\Models\Branch;
use Illuminate\Support\Carbon;

/**
 * Branch-timezone clock. All business-date math (deadlines, cutoffs,
 * SLA clocks, promo windows, report ranges) runs through here so a
 * 00:30 UTC posting lands on the correct Lagos business date.
 * Pure event timestamps (posted_at, moved_at) stay bare now().
 */
class BranchTime
{
    public static function timezone(Branch $branch): string
    {
        // getAttribute is mixed at runtime (a legacy row can hold
        // null), even though the model documents a string.
        $tz = $branch->getAttribute('timezone');

        return is_string($tz) && $tz !== '' ? $tz : 'Africa/Lagos';
    }

    public static function now(Branch $branch): Carbon
    {
        return Carbon::now(self::timezone($branch));
    }

    public static function today(Branch $branch): string
    {
        return self::now($branch)->toDateString();
    }

    public static function parse(Branch $branch, string $date): Carbon
    {
        return Carbon::parse($date, self::timezone($branch))->startOfDay();
    }
}
