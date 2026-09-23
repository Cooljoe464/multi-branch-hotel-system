<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Authority guest-register report. Same (kind, range) always resolves
 * to the same file via the idempotency key; the hash evidences
 * tampering. Incomplete rows ship in the annex, never dropped.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $kind
 * @property Carbon $period_from
 * @property Carbon $period_to
 * @property string $status
 * @property string|null $file_path
 * @property string|null $file_hash
 * @property array<string, mixed>|null $summary
 * @property string $idempotency_key
 * @property int $generated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read User $generator
 */
#[Fillable([
    'branch_id',
    'kind',
    'period_from',
    'period_to',
    'status',
    'file_path',
    'file_hash',
    'summary',
    'idempotency_key',
    'generated_by',
])]
class StatutoryReport extends Model
{
    public const KIND_IMMIGRATION = 'immigration';

    public const KIND_POLICE = 'police';

    public const KIND_TOURISM = 'tourism_board';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'summary' => 'array',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    public static function keyFor(int $branchId, string $kind, string $from, string $to): string
    {
        return "statutory.{$branchId}.{$kind}.{$from}.{$to}";
    }
}
