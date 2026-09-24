<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One raised anomaly, deduplicated by rule + subject. Status is
 * human-driven (open|cleared|confirmed); scans never touch
 * non-open rows.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $rule_code
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property float $score
 * @property string $status
 * @property array<string, mixed>|null $evidence
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 */
#[Fillable([
    'branch_id',
    'rule_code',
    'subject_type',
    'subject_id',
    'score',
    'status',
    'evidence',
])]
class AnomalyFinding extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLEARED = 'cleared';

    public const STATUS_CONFIRMED = 'confirmed';

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'evidence' => 'array',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }
}
