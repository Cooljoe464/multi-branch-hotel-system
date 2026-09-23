<?php

namespace App\Models;

use App\Services\GuestDedupService;
use Database\Factories\GuestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $last_stayed_at
 * @property int|null $master_guest_id
 * @property string|null $dedup_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string $full_name
 * @property-read HasMany<GuestPreference, $this> $preferences
 * @property-read HasMany<Reservation, $this> $reservations
 * @property-read HasMany<Reservation, $this> $allReservations
 * @property-read BelongsTo<Guest, $this> $master
 * @property-read Collection<int, GuestIdentityDocument> $identityDocuments
 * @property-read LoyaltyAccount|null $loyaltyAccount
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
    'master_guest_id',
    'dedup_hash',
])]
class Guest extends Model
{
    /** @use HasFactory<GuestFactory> */
    use HasFactory;

    use LogsActivity;
    use Notifiable;
    use SoftDeletes;

    protected static function boot(): void
    {
        parent::boot();

        // Dedup hash follows every profile write; merges read it,
        // never compute-then-compare races.
        static::saving(function (Guest $guest) {
            $guest->dedup_hash = GuestDedupService::hashFor(
                $guest->first_name ?? '',
                $guest->last_name ?? '',
                $guest->phone,
                $guest->date_of_birth,
            );
        });
    }

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'total_stays' => 'integer',
            'total_nights' => 'integer',
            'total_spent' => 'integer',
            'metadata' => 'array',
            'last_stayed_at' => 'datetime',
            'id_number' => 'encrypted',
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

    /** @return BelongsTo<Guest, $this> */
    public function master(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'master_guest_id');
    }

    /** @return HasMany<GuestIdentityDocument, $this> */
    public function identityDocuments(): HasMany
    {
        return $this->hasMany(GuestIdentityDocument::class);
    }

    /** @return HasOne<LoyaltyAccount, $this> */
    public function loyaltyAccount(): HasOne
    {
        return $this->hasOne(LoyaltyAccount::class);
    }

    /** @return HasMany<GuestPreference, $this> */
    public function preferences(): HasMany
    {
        return $this->hasMany(GuestPreference::class);
    }

    /** @return HasMany<Reservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /** @return HasMany<Reservation, $this> */
    public function allReservations(): HasMany
    {
        return $this->reservations();
    }

    // --- Accessors ---

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    // --- Scopes ---

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForEmail(Builder $query, string $email): Builder
    {
        return $query->where('email', $email);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithVipStatus(Builder $query, string $status): Builder
    {
        return $query->where('vip_status', $status);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVip(Builder $query): Builder
    {
        return $query->where('vip_status', '!=', 'none');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeReturning(Builder $query): Builder
    {
        return $query->where('total_stays', '>', 1);
    }

    // --- Methods ---

    public function incrementStay(int $nights, int $amount): void
    {
        $this->update([
            'total_stays' => $this->total_stays + 1,
            'total_nights' => $this->total_nights + $nights,
            'total_spent' => $this->total_spent + $amount,
            'last_stayed_at' => now(),
        ]);

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
        /** @var GuestPreference|null $pref */
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

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
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
