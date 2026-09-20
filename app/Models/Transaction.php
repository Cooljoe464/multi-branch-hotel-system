<?php

namespace App\Models;

use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $folio_id
 * @property string $type
 * @property string $category
 * @property string $description
 * @property int $amount
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property int|null $posted_by
 * @property bool $is_taxable
 * @property int $tax_amount
 * @property bool $is_voided
 * @property Carbon|null $voided_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Folio $folio
 * @property-read User|null $poster
 * @property-read Model|null $reference
 */
#[Fillable([
    'folio_id',
    'currency_code',
    'business_date',
    'type',
    'category',
    'description',
    'amount',
    'reference_type',
    'reference_id',
    'posted_by',
    'is_taxable',
    'tax_amount',
    'is_voided',
    'voided_at',
    'metadata',
])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'tax_amount' => 'integer',
            'business_date' => 'date',
            'is_taxable' => 'boolean',
            'is_voided' => 'boolean',
            'voided_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Folio, $this> */
    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    /** @return BelongsTo<User, $this> */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /** @return MorphTo<Model, $this> */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDebits(Builder $query): Builder
    {
        return $query->where('type', 'debit');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCredits(Builder $query): Builder
    {
        return $query->where('type', 'credit');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeTaxable(Builder $query): Builder
    {
        return $query->where('is_taxable', true);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForFolio(Builder $query, int $folioId): Builder
    {
        return $query->where('folio_id', $folioId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNotVoided(Builder $query): Builder
    {
        return $query->where('is_voided', false);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    public function void(): bool
    {
        if ($this->is_voided) {
            return false;
        }

        $result = $this->update([
            'is_voided' => true,
            'voided_at' => now(),
        ]);

        if ($result) {
            $folio = $this->folio ?? Folio::find($this->folio_id);
            if ($folio) {
                $folio->balance = $folio->outstanding_balance;
                $folio->save();
            }
        }

        return $result;
    }
}
