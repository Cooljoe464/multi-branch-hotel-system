<?php

namespace App\Models;

use App\Concerns\HasOptimisticLock;
use App\Events\KotItemStatusUpdated;
use Database\Factories\KotItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $pos_charge_id
 * @property int $branch_id
 * @property string $outlet
 * @property string $item_name
 * @property int $quantity
 * @property int $version
 * @property string $status
 * @property string $course
 * @property string|null $notes
 * @property string $priority
 * @property Carbon|null $prepared_at
 * @property Carbon|null $served_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PosCharge $posCharge
 * @property-read Branch $branch
 */
#[Fillable([
    'pos_charge_id',
    'branch_id',
    'outlet',
    'item_name',
    'quantity',
    'status',
    'course',
    'notes',
    'priority',
    'prepared_at',
    'served_at',
    'version',
])]
class KotItem extends Model
{
    /** @use HasFactory<KotItemFactory> */
    use HasFactory;

    use HasOptimisticLock;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'prepared_at' => 'datetime',
            'served_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<PosCharge, $this> */
    public function posCharge(): BelongsTo
    {
        return $this->belongsTo(PosCharge::class);
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
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePreparing(Builder $query): Builder
    {
        return $query->where('status', 'preparing');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', 'ready');
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
    public function scopeForOutlet(Builder $query, string $outlet): Builder
    {
        return $query->where('outlet', $outlet);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRush(Builder $query): Builder
    {
        return $query->where('priority', 'rush');
    }

    public function markPreparing(): bool
    {
        $previousStatus = $this->status;
        $result = $this->update(['status' => 'preparing']);

        if ($result) {
            KotItemStatusUpdated::dispatch($this, $previousStatus);
        }

        return $result;
    }

    public function markReady(): bool
    {
        $previousStatus = $this->status;
        $result = $this->update([
            'status' => 'ready',
            'prepared_at' => now(),
        ]);

        if ($result) {
            KotItemStatusUpdated::dispatch($this, $previousStatus);
        }

        return $result;
    }

    public function markServed(): bool
    {
        $previousStatus = $this->status;
        $result = $this->update([
            'status' => 'served',
            'served_at' => now(),
        ]);

        if ($result) {
            KotItemStatusUpdated::dispatch($this, $previousStatus);
        }

        return $result;
    }

    public function cancel(): bool
    {
        $previousStatus = $this->status;
        $result = $this->update(['status' => 'cancelled']);

        if ($result) {
            KotItemStatusUpdated::dispatch($this, $previousStatus);
        }

        return $result;
    }
}
