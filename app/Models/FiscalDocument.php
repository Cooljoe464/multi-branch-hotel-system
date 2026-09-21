<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * E-invoice document issued through a fiscal provider (FIRS first).
 * Retry-safe via the unique idempotency key; checkout never blocks on it.
 *
 * @property int $id
 * @property int $branch_id
 * @property int|null $folio_id
 * @property string $provider
 * @property string $status
 * @property string|null $irn
 * @property array<string, mixed>|null $payload
 * @property string|null $last_error
 * @property int $attempts
 * @property string $idempotency_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read Folio|null $folio
 */
#[Fillable([
    'branch_id',
    'folio_id',
    'provider',
    'status',
    'irn',
    'payload',
    'last_error',
    'attempts',
    'idempotency_key',
])]
class FiscalDocument extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
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

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
