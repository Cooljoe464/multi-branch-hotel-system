<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Immutable fiscal invoice or credit note managed through an e-invoice provider.
 * Retry-safe via the unique idempotency key; checkout never blocks on it.
 *
 * @property int $id
 * @property int $branch_id
 * @property int|null $folio_id
 * @property string $document_type
 * @property int|null $original_document_id
 * @property string $provider
 * @property string $status
 * @property string|null $irn
 * @property array<string, mixed>|null $payload
 * @property string|null $last_error
 * @property int $attempts
 * @property string $idempotency_key
 * @property string|null $digital_stamp
 * @property string|null $qr_data
 * @property string|null $response_hash
 * @property Carbon|null $submitted_at
 * @property Carbon|null $cleared_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read Folio|null $folio
 */
#[Fillable([
    'branch_id',
    'folio_id',
    'document_type',
    'original_document_id',
    'provider',
    'status',
    'irn',
    'payload',
    'last_error',
    'attempts',
    'idempotency_key',
    'digital_stamp',
    'qr_data',
    'response_hash',
    'submitted_at',
    'cleared_at',
])]
class FiscalDocument extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_READY = 'ready';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_CLEARED = 'cleared';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PENDING = self::STATUS_READY;

    public const STATUS_ISSUED = self::STATUS_CLEARED;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'cleared_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Folio, $this> */
    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    /** @return BelongsTo<FiscalDocument, $this> */
    public function originalDocument(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_document_id');
    }

    /** @return HasMany<FiscalDocument, $this> */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'original_document_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_READY, self::STATUS_SUBMITTED]);
    }
}
