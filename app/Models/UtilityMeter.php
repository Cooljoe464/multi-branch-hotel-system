<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $branch_id
 * @property string $name
 * @property string $meter_number
 * @property string $type
 * @property int $tariff_rate_minor
 * @property float $current_reading
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 */
#[Fillable([
    'branch_id',
    'name',
    'meter_number',
    'type',
    'tariff_rate_minor',
    'current_reading',
])]
class UtilityMeter extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'tariff_rate_minor' => 'integer',
            'current_reading' => 'float',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
