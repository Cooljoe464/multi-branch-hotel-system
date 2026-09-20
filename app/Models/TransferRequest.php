<?php

namespace App\Models;

use Database\Factories\TransferRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $from_branch_id
 * @property int $to_branch_id
 * @property int|null $requested_by
 * @property string $status
 * @property array<int, array{name: string, quantity: int}> $items
 * @property string|null $notes
 * @property Carbon|null $approved_at
 * @property Carbon|null $shipped_at
 * @property Carbon|null $received_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Branch $fromBranch
 * @property-read Branch $toBranch
 * @property-read User|null $requester
 */
#[Fillable([
    'from_branch_id',
    'to_branch_id',
    'requested_by',
    'status',
    'items',
    'notes',
    'approved_at',
    'shipped_at',
    'received_at',
    'metadata',
])]
class TransferRequest extends Model
{
    /** @use HasFactory<TransferRequestFactory> */
    use HasFactory;

    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'approved_at' => 'datetime',
            'shipped_at' => 'datetime',
            'received_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('from_branch_id', $branchId)
            ->orWhere('to_branch_id', $branchId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }
}
