<?php

namespace App\Models;

use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $slug
 * @property string|null $address
 * @property string $city
 * @property string|null $state
 * @property string $country
 * @property string|null $postal_code
 * @property string|null $phone
 * @property string|null $email
 * @property string $timezone
 * @property string $currency_code
 * @property string $currency_symbol
 * @property string|null $cdr_secret
 * @property float $tax_rate
 * @property string $tax_label
 * @property bool $is_active
 * @property bool $is_primary
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, User> $users
 * @property-read Collection<int, RoomType> $roomTypes
 * @property-read Collection<int, Room> $rooms
 * @property-read Collection<int, Reservation> $reservations
 * @property-read Collection<int, Task> $tasks
 * @property-read Collection<int, MaintenanceTicket> $maintenanceTickets
 * @property-read Collection<int, Folio> $folios
 * @property-read Collection<int, DailyLedger> $dailyLedgers
 * @property-read Collection<int, PosCharge> $posCharges
 * @property-read Collection<int, PaymentTransaction> $paymentTransactions
 */
#[Fillable([
    'name',
    'code',
    'slug',
    'address',
    'city',
    'state',
    'country',
    'postal_code',
    'phone',
    'email',
    'timezone',
    'currency_code',
    'currency_symbol',
    'cdr_secret',
    'current_business_date',
    'overbooking_policy',
    'tax_rate',
    'tax_label',
    'is_active',
    'is_primary',
    'settings',
    'metadata',
])]
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'metadata' => 'array',
            'is_active' => 'boolean',
            'is_primary' => 'boolean',
            'tax_rate' => 'decimal:2',
            'current_business_date' => 'date',
            'overbooking_policy' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'code', 'is_active', 'is_primary'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_branch')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    /** @return HasMany<RoomType, $this> */
    public function roomTypes(): HasMany
    {
        return $this->hasMany(RoomType::class);
    }

    /** @return HasMany<Room, $this> */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    /** @return HasMany<Reservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** @return HasMany<MaintenanceTicket, $this> */
    public function maintenanceTickets(): HasMany
    {
        return $this->hasMany(MaintenanceTicket::class);
    }

    /** @return HasMany<Folio, $this> */
    public function folios(): HasMany
    {
        return $this->hasMany(Folio::class);
    }

    /** @return HasMany<DailyLedger, $this> */
    public function dailyLedgers(): HasMany
    {
        return $this->hasMany(DailyLedger::class);
    }

    /** @return HasMany<BusinessDate, $this> */
    public function businessDates(): HasMany
    {
        return $this->hasMany(BusinessDate::class);
    }

    /** @return HasMany<PosCharge, $this> */
    public function posCharges(): HasMany
    {
        return $this->hasMany(PosCharge::class);
    }

    /** @return HasMany<PaymentTransaction, $this> */
    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
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
    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('is_primary', true);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCountry(Builder $query, string $country): Builder
    {
        return $query->where('country', $country);
    }
}
