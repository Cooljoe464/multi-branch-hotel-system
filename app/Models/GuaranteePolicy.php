<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Guarantee terms for a branch (optionally per rate plan): deposit
 * schedule, free-cancellation window, no-show fee and hold lifetime.
 * Rules shape: {deposit_bps, due_hours_before_arrival, hold_hours,
 * cancel_free_until_hours, no_show_fee: first_night|percent,
 * no_show_fee_bps}.
 *
 * @property int $id
 * @property int $branch_id
 * @property int|null $rate_plan_id
 * @property string $kind
 * @property array<string, mixed>|null $rules
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read RatePlan|null $ratePlan
 */
#[Fillable([
    'branch_id',
    'rate_plan_id',
    'kind',
    'rules',
    'is_active',
])]
class GuaranteePolicy extends Model
{
    public const KIND_DEPOSIT = 'deposit_schedule';

    public const KIND_CARD = 'card_guarantee';

    public const KIND_COMPANY = 'company_guarantee';

    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<RatePlan, $this> */
    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return array<string, mixed>
     */
    public function ruleSet(): array
    {
        $rules = $this->rules;

        return is_array($rules) ? $rules : [];
    }

    public function ruleInt(string $key, int $default = 0): int
    {
        $value = $this->ruleSet()[$key] ?? $default;

        return is_int($value) ? $value : $default;
    }

    public function ruleString(string $key, string $default = ''): string
    {
        $value = $this->ruleSet()[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }
}
