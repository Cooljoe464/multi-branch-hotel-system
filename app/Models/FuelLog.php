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
 * @property int|null $generator_id
 * @property string $log_type
 * @property float $liters
 * @property int $cost_per_liter_minor
 * @property int $total_cost_minor
 * @property string|null $supplier_name
 * @property string|null $invoice_number
 * @property float|null $dipstick_level_cm
 * @property int $logged_by
 * @property Carbon $logged_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read Generator|null $generator
 * @property-read User $author
 */
#[Fillable([
    'branch_id',
    'generator_id',
    'log_type',
    'liters',
    'cost_per_liter_minor',
    'total_cost_minor',
    'supplier_name',
    'invoice_number',
    'dipstick_level_cm',
    'logged_by',
    'logged_at',
])]
class FuelLog extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'liters' => 'float',
            'cost_per_liter_minor' => 'integer',
            'total_cost_minor' => 'integer',
            'dipstick_level_cm' => 'float',
            'logged_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Generator, $this> */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(Generator::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by');
    }
}
