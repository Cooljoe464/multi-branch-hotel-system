<?php

namespace App\Models;

use Database\Factories\RatePlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $branch_id
 * @property int|null $room_type_id
 * @property string $name
 * @property string $code
 * @property string $type
 * @property float $rate_multiplier
 * @property bool $is_negotiable
 * @property int|null $min_rate
 * @property int|null $max_rate
 * @property string $valid_from
 * @property string|null $valid_to
 * @property bool $is_active
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Branch $branch
 * @property-read RoomType|null $roomType
 */
#[Fillable([
    'branch_id',
    'currency_code',
    'room_type_id',
    'name',
    'code',
    'type',
    'rate_multiplier',
    'is_negotiable',
    'min_rate',
    'max_rate',
    'valid_from',
    'valid_to',
    'is_active',
    'metadata',
])]
class RatePlan extends Model
{
    /** @use HasFactory<RatePlanFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'rate_multiplier' => 'float',
            'is_negotiable' => 'boolean',
            'min_rate' => 'integer',
            'max_rate' => 'integer',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'code', 'type', 'rate_multiplier', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
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
    public function scopeForRoomType(Builder $query, int $roomTypeId): Builder
    {
        return $query->where('room_type_id', $roomTypeId);
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
    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->where('valid_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date);
            });
    }
}
