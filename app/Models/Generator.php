<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $branch_id
 * @property string $name
 * @property string|null $model
 * @property int $kva_rating
 * @property float $fuel_capacity_liters
 * @property float $liters_per_hour_rated
 * @property float $current_meter_hours
 * @property string $status
 * @property Carbon|null $last_serviced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 */
#[Fillable([
    'branch_id',
    'name',
    'model',
    'kva_rating',
    'fuel_capacity_liters',
    'liters_per_hour_rated',
    'current_meter_hours',
    'status',
    'last_serviced_at',
])]
class Generator extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'kva_rating' => 'integer',
            'fuel_capacity_liters' => 'float',
            'liters_per_hour_rated' => 'float',
            'current_meter_hours' => 'float',
            'last_serviced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<GeneratorRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(GeneratorRun::class);
    }

    /** @return HasMany<FuelLog, $this> */
    public function fuelLogs(): HasMany
    {
        return $this->hasMany(FuelLog::class);
    }
}
