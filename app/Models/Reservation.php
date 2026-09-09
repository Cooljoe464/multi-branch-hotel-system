<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $branch_id
 * @property int|null $room_id
 * @property int $room_type_id
 * @property string $confirmation_number
 * @property string $status
 * @property string $source
 * @property string $guest_name
 * @property string|null $guest_email
 * @property string|null $guest_phone
 * @property string|null $guest_notes
 * @property int $adults
 * @property int $children
 * @property string $check_in_date
 * @property string $check_out_date
 * @property Carbon|null $actual_check_in_at
 * @property Carbon|null $actual_check_out_at
 * @property int $room_rate
 * @property int $total_amount
 * @property int $amount_paid
 * @property string $payment_status
 * @property bool $is_group_booking
 * @property string|null $group_id
 * @property array|null $special_requests
 * @property array|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Branch $branch
 * @property-read Room|null $room
 * @property-read RoomType $roomType
 * @property-read Guest|null $guest
 */
#[Fillable([
    'branch_id',
    'room_id',
    'room_type_id',
    'guest_id',
    'confirmation_number',
    'status',
    'source',
    'guest_name',
    'guest_email',
    'guest_phone',
    'guest_notes',
    'adults',
    'children',
    'check_in_date',
    'check_out_date',
    'room_rate',
    'total_amount',
    'amount_paid',
    'payment_status',
    'is_group_booking',
    'group_id',
    'special_requests',
    'metadata',
])]
class Reservation extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'actual_check_in_at' => 'datetime',
            'actual_check_out_at' => 'datetime',
            'adults' => 'integer',
            'children' => 'integer',
            'room_rate' => 'integer',
            'total_amount' => 'integer',
            'amount_paid' => 'integer',
            'is_group_booking' => 'boolean',
            'special_requests' => 'array',
            'metadata' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'room_id', 'guest_name', 'check_in_date', 'check_out_date'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Reservation $reservation) {
            if (empty($reservation->confirmation_number)) {
                $reservation->confirmation_number = static::generateConfirmationNumber();
            }
        });
    }

    public static function generateConfirmationNumber(): string
    {
        do {
            $number = 'HMS-'.strtoupper(Str::random(8));
        } while (static::where('confirmation_number', $number)->exists());

        return $number;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'confirmed', 'reserved', 'checked_in']);
    }

    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeForStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->where('check_in_date', '<=', $date)
            ->where('check_out_date', '>', $date);
    }

    public function scopeCheckedIn(Builder $query): Builder
    {
        return $query->where('status', 'checked_in');
    }

    public function scopeCheckedOut(Builder $query): Builder
    {
        return $query->where('status', 'checked_out');
    }

    public function scopeGroupBookings(Builder $query): Builder
    {
        return $query->where('is_group_booking', true);
    }

    public function isCurrentlyActive(): bool
    {
        return in_array($this->status, ['checked_in', 'reserved'])
            && $this->check_in_date->lte(now())
            && $this->check_out_date->gt(now());
    }

    public function getNightsAttribute(): int
    {
        return $this->check_in_date->diffInDays($this->check_out_date);
    }
}
