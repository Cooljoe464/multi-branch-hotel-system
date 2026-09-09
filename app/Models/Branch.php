<?php

namespace App\Models;

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
 * @property float $tax_rate
 * @property string $tax_label
 * @property bool $is_active
 * @property bool $is_primary
 * @property array|null $settings
 * @property array|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<User> $users
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
    'tax_rate',
    'tax_label',
    'is_active',
    'is_primary',
    'settings',
    'metadata',
])]
class Branch extends Model
{
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
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'code', 'is_active', 'is_primary'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_branch')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    public function roomTypes(): HasMany
    {
        return $this->hasMany(RoomType::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function maintenanceTickets(): HasMany
    {
        return $this->hasMany(MaintenanceTicket::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('is_primary', true);
    }

    public function scopeForCountry(Builder $query, string $country): Builder
    {
        return $query->where('country', $country);
    }
}
