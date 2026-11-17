<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $branch_id
 * @property string $rule_key
 * @property array<string, mixed> $value
 * @property Carbon $effective_from
 * @property Carbon|null $effective_until
 * @property array<string, mixed>|null $evidence_snapshot
 * @property string|null $approval_reference
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $created_by
 */
#[Fillable([
    'tenant_id',
    'branch_id',
    'rule_key',
    'value',
    'effective_from',
    'effective_until',
    'evidence_snapshot',
    'approval_reference',
    'approved_by',
    'approved_at',
    'created_by',
])]
class TaxRuleVersion extends Model
{
    protected function casts(): array
    {
        return [
            'value' => 'array',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'evidence_snapshot' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeEffectiveOn(Builder $query, Carbon|string $date): Builder
    {
        $day = $date instanceof Carbon ? $date->toDateString() : $date;

        return $query
            ->whereDate('effective_from', '<=', $day)
            ->where(function (Builder $query) use ($day): void {
                $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $day);
            });
    }
}
