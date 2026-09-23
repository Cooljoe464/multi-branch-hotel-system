<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Lost & found ledger: logged → claimed | disposed. Claim payload
 * records who collected what and when.
 *
 * @property int $id
 * @property int $branch_id
 * @property int|null $room_id
 * @property string $description
 * @property string $status
 * @property array<string, mixed>|null $claim
 * @property int|null $logged_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read Room|null $room
 * @property-read User|null $logger
 */
#[Fillable([
    'branch_id',
    'room_id',
    'description',
    'status',
    'claim',
    'logged_by',
])]
class LostFoundItem extends Model
{
    public const STATUS_LOGGED = 'logged';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_DISPOSED = 'disposed';

    protected function casts(): array
    {
        return [
            'claim' => 'array',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<User, $this> */
    public function logger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by');
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
