<?php

namespace App\Models;

use Database\Factories\OverbookingAllowanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $tenant_id
 * @property int $branch_id
 * @property int $room_type_id
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property int $allowed_count
 * @property list<string> $allowed_sources
 * @property string $reason
 * @property int $created_by
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by
 */
#[Fillable([
    'tenant_id', 'branch_id', 'room_type_id', 'starts_on', 'ends_on', 'allowed_count',
    'allowed_sources', 'reason', 'created_by', 'revoked_at', 'revoked_by',
])]
class OverbookingAllowance extends Model
{
    /** @use HasFactory<OverbookingAllowanceFactory> */
    use HasFactory;

    use LogsActivity;

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'allowed_count' => 'integer',
            'allowed_sources' => 'array',
            'revoked_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['branch_id', 'room_type_id', 'starts_on', 'ends_on', 'allowed_count', 'allowed_sources', 'reason', 'revoked_at', 'revoked_by'])
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

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
