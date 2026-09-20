<?php

namespace App\Models;

use Database\Factories\ReservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * @property string|null $guest_notes
 * @property int $adults
 * @property int $children
 * @property Carbon $check_in_date
 * @property Carbon $check_out_date
 * @property Carbon|null $actual_check_in_at
 * @property Carbon|null $actual_check_out_at
 * @property int $room_rate
 * @property int $total_amount
 * @property int $amount_paid
 * @property string $payment_status
 * @property bool $is_group_booking
 * @property string|null $group_id
 * @property array<string, mixed>|null $special_requests
 * @property array<string, mixed>|null $metadata
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
    'currency_code',
    'business_date',
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
    /** @use HasFactory<ReservationFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    protected $appends = ['nights'];

    protected function casts(): array
    {
        return [
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'business_date' => 'date',
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

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /** @return BelongsTo<Guest, $this> */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /** @return HasOne<Folio, $this> */
    public function folio(): HasOne
    {
        return $this->hasOne(Folio::class);
    }

    /** @return HasMany<Folio, $this> */
    public function folios(): HasMany
    {
        return $this->hasMany(Folio::class);
    }

    /** @return HasMany<PosCharge, $this> */
    public function posCharges(): HasMany
    {
        return $this->hasMany(PosCharge::class);
    }

    /** @return HasOne<RegistrationCard, $this> */
    public function registrationCard(): HasOne
    {
        return $this->hasOne(RegistrationCard::class);
    }

    /** @return HasMany<LaundryOrder, $this> */
    public function laundryOrders(): HasMany
    {
        return $this->hasMany(LaundryOrder::class);
    }

    /** @return HasMany<TabletOrder, $this> */
    public function tabletOrders(): HasMany
    {
        return $this->hasMany(TabletOrder::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'confirmed', 'reserved', 'checked_in']);
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
    public function scopeForStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->where('check_in_date', '<=', $date)
            ->where('check_out_date', '>', $date);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCheckedIn(Builder $query): Builder
    {
        return $query->where('status', 'checked_in');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCheckedOut(Builder $query): Builder
    {
        return $query->where('status', 'checked_out');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
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
        return (int) $this->check_in_date->diffInDays($this->check_out_date);
    }

    public function syncPaymentStatus(): void
    {
        $totalPaid = (int) Transaction::where('type', 'credit')
            ->where('category', 'payment')
            ->where('is_voided', false)
            ->whereHas('folio', fn ($q) => $q->where('reservation_id', $this->id))
            ->sum('amount');

        $this->update([
            'amount_paid' => $totalPaid,
            'payment_status' => match (true) {
                $totalPaid >= $this->total_amount => 'paid',
                $totalPaid > 0 => 'partial',
                default => 'pending',
            },
        ]);
    }
}
