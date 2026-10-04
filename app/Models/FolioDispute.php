<?php

namespace App\Models;

use Database\Factories\FolioDisputeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $folio_id
 * @property int|null $transaction_id
 * @property Carbon|null $business_date
 * @property int $disputed_by
 * @property string $status
 * @property string $reason
 * @property string|null $resolution_notes
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property int $amount_disputed
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Folio $folio
 * @property-read Transaction|null $transaction
 * @property-read User $reporter
 * @property-read User|null $resolver
 */
class FolioDispute extends Model
{
    /** @use HasFactory<FolioDisputeFactory> */
    use HasFactory;

    protected $fillable = [
        'folio_id',
        'currency_code',
        'transaction_id',
        'business_date',
        'disputed_by',
        'status',
        'reason',
        'resolution_notes',
        'resolved_by',
        'resolved_at',
        'amount_disputed',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'resolved_at' => 'datetime',
            'amount_disputed' => 'integer',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Folio, $this> */
    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disputed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
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
    public function scopeUnderReview(Builder $query): Builder
    {
        return $query->where('status', 'under_review');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeResolved(Builder $query): Builder
    {
        return $query->where('status', 'resolved');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', 'rejected');
    }

    public function resolve(int $resolverId, string $notes): void
    {
        $this->update([
            'status' => 'resolved',
            'resolved_by' => $resolverId,
            'resolved_at' => now(),
            'resolution_notes' => $notes,
        ]);
    }

    public function reject(int $resolverId, string $notes): void
    {
        $this->update([
            'status' => 'rejected',
            'resolved_by' => $resolverId,
            'resolved_at' => now(),
            'resolution_notes' => $notes,
        ]);
    }

    public function review(): void
    {
        $this->update(['status' => 'under_review']);
    }
}
