<?php

namespace App\Models;

use Database\Factories\RateOverrideFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $branch_id
 * @property int|null $room_type_id
 * @property string $start_date
 * @property string $end_date
 * @property int|null $rate_override
 * @property int|null $mlos
 * @property bool $cta
 * @property bool $ctd
 * @property bool $is_active
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read RoomType|null $roomType
 */
#[Fillable([
    'branch_id',
    'currency_code',
    'room_type_id',
    'start_date',
    'end_date',
    'rate_override',
    'mlos',
    'cta',
    'ctd',
    'is_active',
    'notes',
])]
class RateOverride extends Model
{
    /** @use HasFactory<RateOverrideFactory> */
    use HasFactory;

    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'rate_override' => 'integer',
            'mlos' => 'integer',
            'cta' => 'boolean',
            'ctd' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
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
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForRoomType(Builder $query, ?int $roomTypeId): Builder
    {
        return $query->where(function ($q) use ($roomTypeId) {
            $q->whereNull('room_type_id')
                ->orWhere('room_type_id', $roomTypeId);
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOverlapping(Builder $query, string $startDate, string $endDate, ?int $excludeId = null): Builder
    {
        return $query->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId));
    }
}
