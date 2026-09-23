<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * OAuth link from a property to an external ledger. Tokens are
 * encrypted at rest and refreshed server-side by the drivers.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $provider
 * @property array<string, string>|null $account_map
 * @property bool $sandbox
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 */
#[Fillable([
    'branch_id',
    'provider',
    'account_map',
    'sandbox',
    'is_active',
])]
class AccountingLink extends Model
{
    public const PROVIDERS = ['xero', 'quickbooks', 'sage'];

    protected function casts(): array
    {
        return [
            'account_map' => 'array',
            'sandbox' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @param  array<string, mixed>  $tokens
     */
    public function storeTokens(array $tokens): void
    {
        $this->forceFill(['oauth_enc' => Crypt::encryptString(json_encode($tokens) ?: '{}')])->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function readTokens(): array
    {
        $enc = $this->getAttribute('oauth_enc');

        if (! is_string($enc) || $enc === '') {
            return [];
        }

        $decoded = json_decode(Crypt::decryptString($enc), true);

        if (! is_array($decoded)) {
            return [];
        }

        $tokens = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $tokens[$key] = $value;
            }
        }

        return $tokens;
    }

    public function externalAccountFor(string $chartCode): ?string
    {
        $map = $this->account_map;

        if (! is_array($map)) {
            return null;
        }

        $external = $map[$chartCode] ?? null;

        return is_string($external) && $external !== '' ? $external : null;
    }
}
