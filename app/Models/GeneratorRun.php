<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $generator_id
 * @property Carbon $started_at
 * @property Carbon|null $stopped_at
 * @property float $start_meter_hours
 * @property float|null $end_meter_hours
 * @property float|null $run_hours
 * @property float|null $fuel_consumed_liters
 * @property float|null $kwh_generated
 * @property string $reason
 * @property int $operator_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Generator $generator
 * @property-read User $operator
 */
#[Fillable([
    'generator_id',
    'started_at',
    'stopped_at',
    'start_meter_hours',
    'end_meter_hours',
    'run_hours',
    'fuel_consumed_liters',
    'kwh_generated',
    'reason',
    'operator_id',
])]
class GeneratorRun extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'stopped_at' => 'datetime',
            'start_meter_hours' => 'float',
            'end_meter_hours' => 'float',
            'run_hours' => 'float',
            'fuel_consumed_liters' => 'float',
            'kwh_generated' => 'float',
        ];
    }

    /** @return BelongsTo<Generator, $this> */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(Generator::class);
    }

    /** @return BelongsTo<User, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
