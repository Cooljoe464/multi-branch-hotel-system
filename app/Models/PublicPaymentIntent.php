<?php

namespace App\Models;

use Database\Factories\PublicPaymentIntentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $tenant_id
 * @property int $branch_id
 * @property int $api_key_id
 * @property int $hold_id
 * @property int $quote_id
 * @property int $payment_connection_id
 * @property int $folio_id
 * @property int|null $payment_transaction_id
 * @property int|null $reservation_id
 * @property string $guest_name
 * @property string $guest_email
 * @property string|null $guest_phone
 * @property int $adults
 * @property int $children
 * @property string $status
 * @property string|null $gateway_reference
 * @property string|null $checkout_url
 * @property string $access_token_hash
 * @property int $amount_minor
 * @property string $currency_code
 * @property Carbon $expires_at
 */
#[Fillable([
    'tenant_id', 'branch_id', 'api_key_id', 'hold_id', 'quote_id', 'payment_connection_id',
    'folio_id', 'payment_transaction_id', 'reservation_id', 'guest_name', 'guest_email',
    'guest_phone', 'adults', 'children', 'status', 'gateway_reference', 'checkout_url', 'access_token_hash',
    'amount_minor', 'currency_code', 'expires_at',
])]
class PublicPaymentIntent extends Model
{
    /** @use HasFactory<PublicPaymentIntentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'adults' => 'integer',
            'children' => 'integer',
            'amount_minor' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<ApiKey, $this> */
    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    /** @return BelongsTo<InventoryHold, $this> */
    public function hold(): BelongsTo
    {
        return $this->belongsTo(InventoryHold::class);
    }

    /** @return BelongsTo<PublicApiQuote, $this> */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(PublicApiQuote::class, 'quote_id');
    }

    /** @return BelongsTo<PaymentConnection, $this> */
    public function paymentConnection(): BelongsTo
    {
        return $this->belongsTo(PaymentConnection::class);
    }

    /** @return BelongsTo<Folio, $this> */
    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
