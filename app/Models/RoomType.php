<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $branch_id
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property int $base_rate
 * @property int $max_occupancy
 * @property int $bed_count
 * @property string $bed_type
 * @property bool $is_active
 * @property array|null $amenities
 * @property array|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Branch $branch
 * @property-read Collection<Room> $rooms
 */
#[Fillable([
    'branch_id',
    'name',
    'code',
    'description',
    'base_rate',
    'max_occupancy',
    'bed_count',
    'bed_type',
    'is_active',
    'amenities',
    'metadata',
])]
class RoomType extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'base_rate' => 'integer',
            'max_occupancy' => 'integer',
            'bed_count' => 'integer',
            'is_active' => 'boolean',
            'amenities' => 'array',
            'metadata' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'code', 'base_rate', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }
}
