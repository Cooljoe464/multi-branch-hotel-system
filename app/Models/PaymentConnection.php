<?php

namespace App\Models;

use Database\Factories\PaymentConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $tenant_id
 * @property int|null $branch_id
 * @property string $provider
 * @property string $mode
 * @property string $name
 * @property string $secret_key
 * @property string|null $webhook_secret
 * @property string|null $merchant_reference
 * @property bool $is_default
 * @property string $status
 */
#[Fillable([
    'tenant_id', 'branch_id', 'provider', 'mode', 'name', 'secret_key',
    'webhook_secret', 'merchant_reference', 'is_default', 'status',
])]
class PaymentConnection extends Model
{
    /** @use HasFactory<PaymentConnectionFactory> */
    use HasFactory;

    public const PROVIDERS = ['paystack', 'flutterwave'];

    protected function casts(): array
    {
        return [
            'secret_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'is_default' => 'boolean',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<PaymentTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }
}
