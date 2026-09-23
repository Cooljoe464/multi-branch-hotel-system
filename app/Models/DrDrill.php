<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Quarterly DR drill record. Freshness (latest passed drill under
 * 120 days) is CI-checked so an untested backup can't go quiet.
 *
 * @property int $id
 * @property Carbon $drill_date
 * @property string $mode
 * @property string $status
 * @property int|null $rto_minutes
 * @property int|null $rpo_minutes
 * @property array<string, mixed>|null $checks
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'drill_date',
    'mode',
    'status',
    'rto_minutes',
    'rpo_minutes',
    'checks',
    'notes',
])]
class DrDrill extends Model
{
    protected function casts(): array
    {
        return [
            'drill_date' => 'date',
            'checks' => 'array',
        ];
    }

    public static function isFresh(int $days = 120): bool
    {
        $latest = self::where('status', 'passed')->latest('drill_date')->first();

        return $latest !== null && $latest->drill_date->greaterThanOrEqualTo(now()->subDays($days)->toDateString());
    }
}
