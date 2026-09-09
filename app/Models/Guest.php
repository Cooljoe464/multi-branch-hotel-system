<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property string|null $phone
 * @property Carbon|null $date_of_birth
 * @property string|null $nationality
 * @property string|null $id_type
 * @property string|null $id_number
 * @property string|null $company
 * @property string|null $job_title
 * @property string $vip_status
 * @property int $total_stays
 * @property int $total_nights
 * @property int $total_spent
 * @property string $currency_code
 * @property string $preferred_language
 * @property string $preferred_currency
 * @property string|null $dietary_restrictions
 * @property string|null $special_notes
 * @property string|null $internal_notes
 * @property array|null $metadata
 * @property Carbon|null $last_stayed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string $full_name
 * @property-read HasMany $preferences
 * @property-read HasMany $reservations
 * @property-read HasManyThrough $allReservations
 */
#[Fillable([
    'first_name',
    'last_name',
    'email',
    'phone',
    'date_of_birth',
    'nationality',
    'id_type',
    'id_number',
    'company',
    'job_title',
    'vip_status',
    'total_stays',
    'total_nights',
    'total_spent',
    'currency_code',
    'preferred_language',
    'preferred_currency',
    'dietary_restrictions',
    'special_notes',
    'internal_notes',
    'metadata',
    'last_stayed_at',
])]
class Guest extends Model
{
    use HasFactory;
    use LogsActivity;
    use Notifiable;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'total_stays' => 'integer',
            'total_nights' => 'integer',
            'total_spent' => 'integer',
            'metadata' => 'array',
            'last_stayed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['first_name', 'last_name', 'email', 'vip_status', 'total_stays', 'total_spent'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    // --- Relationships ---

    public function preferences(): HasMany
    {
        return $this->hasMany(GuestPreference::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function allReservations(): HasManyThrough
    {
        return $this->HasManyThrough(Reservation::class, 'guest_id');
    }

    // --- Accessors ---

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    // --- Scopes ---

    public function scopeForEmail(Builder $query, string $email): Builder
    {
        return $query->where('email', $email);
    }

    public function scopeWithVipStatus(Builder $query, string $status): Builder
    {
        return $query->where('vip_status', $status);
    }

    public function scopeVip(Builder $query): Builder
    {
        return $query->where('vip_status', '!=', 'none');
    }

    public function scopeReturning(Builder $query): Builder
    {
        return $query->where('total_stays', '>', 1);
    }

    // --- Methods ---

    public function incrementStay(int $nights, int $amount): void
    {
        $this->increment('total_stays');
        $this->increment('total_nights', $nights);
        $this->increment('total_spent', $amount);
        $this->update(['last_stayed_at' => now()]);

        $this->evaluateVipStatus();
    }

    public function evaluateVipStatus(): void
    {
        $newStatus = match (true) {
            $this->total_stays >= 50 || $this->total_spent >= 5000000 => 'diamond',
            $this->total_stays >= 25 || $this->total_spent >= 2000000 => 'platinum',
            $this->total_stays >= 10 || $this->total_spent >= 500000 => 'gold',
            $this->total_stays >= 3 || $this->total_spent >= 100000 => 'silver',
            default => 'none',
        };

        if ($this->vip_status !== $newStatus) {
            $this->update(['vip_status' => $newStatus]);
        }
    }

    public function getPreference(string $category, string $key): ?string
    {
        $pref = $this->preferences()
            ->where('category', $category)
            ->where('key', $key)
            ->first();

        return $pref?->value;
    }

    public function setPreference(string $category, string $key, string $value, ?string $notes = null): void
    {
        $this->preferences()->updateOrCreate(
            ['category' => $category, 'key' => $key],
            ['value' => $value, 'notes' => $notes]
        );
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        return $query->where(function ($q) use ($search) {
            $q->where('first_name', 'LIKE', "%{$search}%")
                ->orWhere('last_name', 'LIKE', "%{$search}%")
                ->orWhere('email', 'LIKE', "%{$search}%")
                ->orWhere('phone', 'LIKE', "%{$search}%");
        });
    }
}
