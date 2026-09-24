<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tunable anomaly detector. branch_id null = global default;
 * a branch row with the same code overrides it. Threshold
 * feedback writes into params, never into code.
 *
 * @property int $id
 * @property int|null $branch_id
 * @property string $code
 * @property array<string, mixed>|null $params
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch|null $branch
 */
#[Fillable([
    'branch_id',
    'code',
    'params',
    'active',
])]
class AnomalyRule extends Model
{
    protected function casts(): array
    {
        return [
            'params' => 'array',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function param(string $key, mixed $default = null): mixed
    {
        $params = $this->params;

        return is_array($params) && array_key_exists($key, $params) ? $params[$key] : $default;
    }

    public function paramInt(string $key, int $default): int
    {
        $value = $this->param($key, $default);

        return is_int($value) ? $value : $default;
    }

    public function paramFloat(string $key, float $default): float
    {
        $value = $this->param($key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function tune(array $params): void
    {
        $current = is_array($this->params) ? $this->params : [];

        $this->update(['params' => array_merge($current, $params)]);
    }
}
