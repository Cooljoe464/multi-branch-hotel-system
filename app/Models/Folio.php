<?php

namespace App\Models;

use App\Concerns\HasOptimisticLock;
use Database\Factories\FolioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $branch_id
 * @property string $currency_code
 * @property int|null $reservation_id
 * @property int|null $parent_folio_id
 * @property string $folio_number
 * @property string $type
 * @property string $status
 * @property string|null $description
 * @property string|null $guest_name
 * @property string|null $notes
 * @property int $balance
 * @property bool $is_settled
 * @property int $version
 * @property Carbon|null $closed_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Branch $branch
 * @property-read Reservation|null $reservation
 * @property-read Folio|null $parentFolio
 * @property-read Collection<int, Folio> $children
 * @property-read Collection<int, Transaction> $transactions
 * @property-read Collection<int, PosCharge> $posCharges
 * @property-read Collection<int, FolioDispute> $disputes
 */
#[Fillable([
    'branch_id',
    'currency_code',
    'idempotency_key',
    'reservation_id',
    'parent_folio_id',
    'folio_number',
    'type',
    'status',
    'description',
    'guest_name',
    'notes',
    'balance',
    'is_settled',
    'closed_at',
    'metadata',
    'version',
    'is_master',
])]
class Folio extends Model
{
    /** @use HasFactory<FolioFactory> */
    use HasFactory;

    use HasOptimisticLock;
    use LogsActivity;
    use SoftDeletes;

    public const TYPE_INDIVIDUAL = 'individual';

    public const TYPE_MASTER = 'master';

    public const TYPE_CHILD = 'child';

    public const TYPE_STAFF = 'staff';

    public const TYPE_NON_GUEST = 'non_guest';

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'is_settled' => 'boolean',
            'closed_at' => 'datetime',
            'metadata' => 'array',
            'version' => 'integer',
            'is_master' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'balance', 'is_settled', 'closed_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Folio $folio) {
            if (empty($folio->folio_number)) {
                $folio->folio_number = static::generateFolioNumber();
            }
        });
    }

    public static function generateFolioNumber(): string
    {
        $prefix = 'FOL-'.now()->format('Ymd-');

        do {
            $number = $prefix.strtoupper(Str::random(4));
        } while (static::where('folio_number', $number)->exists());

        return $number;
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return BelongsTo<Folio, $this> */
    public function parentFolio(): BelongsTo
    {
        return $this->belongsTo(Folio::class, 'parent_folio_id');
    }

    /** @return HasMany<Folio, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Folio::class, 'parent_folio_id');
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @return HasMany<FolioWindow, $this> */
    public function windows(): HasMany
    {
        return $this->hasMany(FolioWindow::class);
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

    /** @return HasMany<FolioDispute, $this> */
    public function disputes(): HasMany
    {
        return $this->hasMany(FolioDispute::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
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
    public function scopeMaster(Builder $query): Builder
    {
        return $query->where('type', 'master');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeChild(Builder $query): Builder
    {
        return $query->where('type', 'child');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeIndividual(Builder $query): Builder
    {
        return $query->where('type', 'individual');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeStaff(Builder $query): Builder
    {
        return $query->where('type', 'staff');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNonGuest(Builder $query): Builder
    {
        return $query->where('type', 'non_guest');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSearch(Builder $query, string $search): Builder
    {
        return $query->where(function ($q) use ($search) {
            $q->where('folio_number', 'ilike', "%{$search}%")
                ->orWhere('guest_name', 'ilike', "%{$search}%")
                ->orWhereHas('reservation', function ($rq) use ($search) {
                    $rq->where('confirmation_number', 'ilike', "%{$search}%");
                });
        });
    }

    public function getDebitsTotalAttribute(): int
    {
        return (int) $this->transactions()
            ->where('type', 'debit')
            ->where('is_voided', false)
            ->sum('amount');
    }

    public function getCreditsTotalAttribute(): int
    {
        return (int) $this->transactions()
            ->where('type', 'credit')
            ->where('is_voided', false)
            ->sum('amount');
    }

    public function getOutstandingBalanceAttribute(): int
    {
        return $this->debits_total - $this->credits_total;
    }
}
