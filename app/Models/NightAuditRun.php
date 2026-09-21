<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One auditable night-audit execution. Steps record progress so a crash
 * resumes instead of re-posting; the unique key makes concurrent and
 * retried runs collapse into one.
 *
 * @property int $id
 * @property int $branch_id
 * @property Carbon $business_date
 * @property string $status
 * @property string $idempotency_key
 * @property array<string, mixed>|null $steps
 * @property array<string, mixed>|null $result
 * @property int|null $run_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 */
#[Fillable([
    'branch_id',
    'business_date',
    'status',
    'idempotency_key',
    'steps',
    'result',
    'run_by',
])]
class NightAuditRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_POSTED = 'posted';

    public const STATUS_RECONCILED = 'reconciled';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'steps' => 'array',
            'result' => 'array',
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
    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_POSTED, self::STATUS_RECONCILED], true);
    }
}
