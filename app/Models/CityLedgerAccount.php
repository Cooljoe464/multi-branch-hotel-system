<?php

namespace App\Models;

use Database\Factories\CityLedgerAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $branch_id
 * @property string|null $currency_code
 * @property string $company_name
 * @property string $contact_name
 * @property string $email
 * @property string|null $phone
 * @property int $credit_limit
 * @property int $balance_owing
 * @property int $payment_terms_days
 * @property bool $is_active
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Branch $branch
 * @property-read Collection<int, CityLedgerTransaction> $transactions
 */
#[Fillable([
    'branch_id',
    'currency_code',
    'company_name',
    'contact_name',
    'email',
    'phone',
    'credit_limit',
    'payment_terms_days',
    'is_active',
    'balance_owing',
])]
class CityLedgerAccount extends Model
{
    /** @use HasFactory<CityLedgerAccountFactory> */
    use HasFactory;

    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'credit_limit' => 'integer',
            'balance_owing' => 'integer',
            'payment_terms_days' => 'integer',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<CityLedgerTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(CityLedgerTransaction::class);
    }
}
