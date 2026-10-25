<?php

namespace App\Services\Accounting;

use App\Exceptions\AvailabilityException;
use App\Models\AccountingLink;
use App\Models\Branch;
use App\Services\Security\SecretManager;
use App\Services\Security\VendorRateLimiter;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * OAuth 2.0 authorization-code flow for the accounting ledgers.
 *
 * Client credentials resolve branch-scoped (branch_secrets) before
 * .env so every property can hold its own app registration; the
 * `state` is an encrypted, single-use nonce held in cache, so a
 * callback from anywhere but our own authorize redirect is refused.
 * Access tokens refresh themselves here (vendor tokens expire in
 * minutes, unlike stored vendor API keys, which only rotate on
 * manual request).
 */
class AccountingOAuthManager
{
    /** Token calls allowed per branch per minute. */
    public const RATE_LIMIT = 20;

    public const RATE_DECAY = 60;

    /**
     * @var array<string, array{authorize: string, token: string, refresh: string, scope: string}>
     */
    public const PROVIDERS = [
        'xero' => [
            'authorize' => 'https://login.xero.com/identity/connect/authorize',
            'token' => 'https://identity.xero.com/oauth2/token',
            'refresh' => 'https://identity.xero.com/oauth2/token',
            'scope' => 'openid profile offline_access accounting.manualjournals',
        ],
        'quickbooks' => [
            'authorize' => 'https://appcenter.intuit.com/connect/oauth2',
            'token' => 'https://oauth2.platform.intuit.com/oauth2/v1/tokens/authorization_code',
            'refresh' => 'https://oauth2.platform.intuit.com/oauth2/v1/tokens/refresh',
            'scope' => 'com.intuit.quickbooks.accounting',
        ],
        'sage' => [
            'authorize' => 'https://oauth.sage.com/authorize',
            'token' => 'https://oauth.sage.com/token',
            'refresh' => 'https://oauth.sage.com/token',
            'scope' => 'journals.content offline_access',
        ],
    ];

    private const STATE_TTL_MINUTES = 20;

    /** Refresh this many seconds before the vendor expires the token. */
    private const EXPIRY_GRACE = 30;

    public function __construct(
        private SecretManager $secrets,
        private VendorRateLimiter $limiter,
    ) {}

    /**
     * @return array{client_id: ?string, client_secret: ?string}
     */
    public function clientCredentials(Branch $branch, string $provider): array
    {
        $this->assertProvider($provider);

        return [
            'client_id' => $this->secrets->get($branch, "{$provider}.client_id")
                ?? $this->stringOrNull(config("services.{$provider}.client_id")),
            'client_secret' => $this->secrets->get($branch, "{$provider}.client_secret")
                ?? $this->stringOrNull(config("services.{$provider}.client_secret")),
        ];
    }

    public function redirectUri(): string
    {
        return route('accounting.oauth.callback');
    }

    /**
     * Where the property admin starts the connect: we mint a one-time
     * state and send the browser to the vendor's consent screen.
     */
    public function authorizeUrl(AccountingLink $link): string
    {
        $provider = $this->endpoints($link->provider);
        $credentials = $this->clientCredentials($link->branch, $link->provider);

        if ($credentials['client_id'] === null) {
            throw new AvailabilityException(
                'ACCOUNTING_AUTH',
                "No {$link->provider} client id configured for this property.",
            );
        }

        return $provider['authorize'].'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $credentials['client_id'],
            'redirect_uri' => $this->redirectUri(),
            'scope' => $provider['scope'],
            'state' => $this->mintState($link),
        ]);
    }

    /**
     * Turn a vendor callback into stored, ready-to-use tokens.
     *
     * @param  string|null  $realmId  QuickBooks company id echoed back on the callback.
     */
    public function complete(AccountingLink $link, string $state, string $code, ?string $realmId = null): AccountingLink
    {
        $this->verifyState($link, $state);

        $credentials = $this->clientCredentials($link->branch, $link->provider);

        if ($credentials['client_id'] === null || $credentials['client_secret'] === null) {
            throw new AvailabilityException('ACCOUNTING_AUTH', 'OAuth client credentials are not configured.');
        }

        $response = $this->client($link)->asForm()->post($this->endpoints($link->provider)['token'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
        ]);

        $tokens = $this->tokenPayload($link, $response);

        if ($link->provider === 'quickbooks' && is_string($realmId) && $realmId !== '') {
            $tokens['realm_id'] = $realmId;
        }

        if ($link->provider === 'xero') {
            $tokens['tenant_id'] = $this->xeroTenantId($tokens['access_token']);
        }

        $link->storeTokens($tokens);
        $link->forceFill(['is_active' => true])->save();

        return $link;
    }

    /**
     * Fresh token set for an export: refreshes transparently when the
     * access token is within the grace window of expiring.
     *
     * @return array<string, mixed>
     */
    public function tokens(AccountingLink $link): array
    {
        $tokens = $link->readTokens();

        $access = $tokens['access_token'] ?? null;

        if (! is_string($access) || $access === '') {
            return $tokens;
        }

        if (! $this->isExpiring($tokens)) {
            return $tokens;
        }

        return $this->refresh($link);
    }

    /**
     * Exchange the refresh token for a new access token, keeping the
     * provider-specific ids (tenant/realm) intact.
     *
     * @return array<string, mixed>
     */
    public function refresh(AccountingLink $link): array
    {
        $tokens = $link->readTokens();
        $refreshToken = $tokens['refresh_token'] ?? null;

        if (! is_string($refreshToken) || $refreshToken === '') {
            throw new AvailabilityException(
                'ACCOUNTING_AUTH',
                "{$link->provider} session expired. Reconnect this property.",
            );
        }

        $credentials = $this->clientCredentials($link->branch, $link->provider);

        $response = $this->client($link)->asForm()->post($this->endpoints($link->provider)['refresh'], [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
        ]);

        $fresh = $this->tokenPayload($link, $response, requireRefresh: false);
        $merged = array_merge($tokens, $fresh);

        $link->storeTokens($merged);

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $tokens
     */
    private function isExpiring(array $tokens): bool
    {
        $expiresAt = $tokens['expires_at'] ?? null;

        if (! is_string($expiresAt) || $expiresAt === '') {
            return false;
        }

        try {
            $deadline = Carbon::parse($expiresAt);
        } catch (\Throwable) {
            return false;
        }

        return now()->addSeconds(self::EXPIRY_GRACE)->gte($deadline);
    }

    /**
     * One-time state: an encrypted nonce whose receipt lives in cache.
     */
    private function mintState(AccountingLink $link): string
    {
        $nonce = Str::random(48);

        Cache::put($this->stateKey($nonce), [
            'link_id' => $link->id,
            'provider' => $link->provider,
        ], now()->addMinutes(self::STATE_TTL_MINUTES));

        return Crypt::encryptString($nonce);
    }

    private function verifyState(AccountingLink $link, string $state): void
    {
        $expected = $this->statePayload($state);

        if (! is_array($expected)
            || $expected['link_id'] !== $link->id
            || $expected['provider'] !== $link->provider) {
            throw new AvailabilityException('ACCOUNTING_STATE', 'The connect session is not valid. Start again.');
        }
    }

    /**
     * The link a callback state belongs to (peeked, not consumed), so the
     * controller can enforce branch access on the target property.
     */
    public function pendingLink(string $state): ?AccountingLink
    {
        $expected = $this->statePayload($state, consume: false);

        if ($expected === null || ! isset($expected['link_id']) || ! is_int($expected['link_id'])) {
            return null;
        }

        return AccountingLink::find($expected['link_id']);
    }

    /**
     * Decrypts the state and (optionally) pulls its one-time cache entry —
     * consumed on verification, so a replayed callback finds nothing.
     *
     * @return array<string, mixed>|null
     */
    private function statePayload(string $state, bool $consume = true): ?array
    {
        try {
            $nonce = Crypt::decryptString($state);
        } catch (\Throwable) {
            return null;
        }

        $key = $this->stateKey($nonce);
        $expected = $consume ? Cache::pull($key) : Cache::get($key);

        if (! is_array($expected)) {
            return null;
        }

        $payload = [];

        foreach ($expected as $name => $value) {
            if (is_string($name)) {
                $payload[$name] = $value;
            }
        }

        return $payload;
    }

    private function stateKey(string $nonce): string
    {
        return 'accounting.oauth.state:'.hash('sha256', $nonce);
    }

    /**
     * @return array{
     *     access_token: string,
     *     token_type: string,
     *     connected_at: string,
     *     refresh_token?: string,
     *     expires_at?: string
     * }
     */
    private function tokenPayload(AccountingLink $link, Response $response, bool $requireRefresh = true): array
    {
        if ($response->failed()) {
            throw new AvailabilityException(
                'ACCOUNTING_AUTH',
                "{$link->provider} rejected the token request ({$response->status()}).",
            );
        }

        $access = $this->stringOrNull($response->json('access_token'));

        if ($access === null) {
            throw new AvailabilityException('ACCOUNTING_AUTH', "{$link->provider} returned no access token.");
        }

        $tokens = [
            'access_token' => $access,
            'token_type' => $this->stringOrNull($response->json('token_type')) ?? 'Bearer',
            'connected_at' => now()->toIso8601String(),
        ];

        $refresh = $this->stringOrNull($response->json('refresh_token'));

        if ($refresh !== null) {
            $tokens['refresh_token'] = $refresh;
        } elseif ($requireRefresh) {
            throw new AvailabilityException('ACCOUNTING_AUTH', "{$link->provider} returned no refresh token.");
        }

        $expiresIn = $response->json('expires_in');

        if (is_int($expiresIn) || is_numeric($expiresIn)) {
            $tokens['expires_at'] = now()->addSeconds((int) $expiresIn)->toIso8601String();
        }

        return $tokens;
    }

    /**
     * Xero scopes tokens per organisation: the tenant id must come
     * from the connections list before any API call can be signed.
     */
    private function xeroTenantId(string $accessToken): string
    {
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(30)
            ->get('https://api.xero.com/connections');

        $tenant = $response->json('0.tenantId');

        if (! is_string($tenant) || $tenant === '') {
            throw new AvailabilityException('ACCOUNTING_AUTH', 'Xero returned no connected organisation.');
        }

        return $tenant;
    }

    /**
     * Token exchange sits behind the branch's vendor-call budget so a
     * reconnect storm cannot trip vendor-side abuse detection.
     */
    private function client(AccountingLink $link): PendingRequest
    {
        if (! $this->limiter->attempt('accounting-oauth', $link->branch_id, self::RATE_LIMIT, self::RATE_DECAY)) {
            throw new AvailabilityException('ACCOUNTING_RATE', 'Too many token requests. Retry shortly.');
        }

        return Http::acceptJson()->timeout(30);
    }

    /**
     * @return array{authorize: string, token: string, refresh: string, scope: string}
     */
    private function endpoints(string $provider): array
    {
        $this->assertProvider($provider);

        return self::PROVIDERS[$provider];
    }

    private function assertProvider(string $provider): void
    {
        if (! isset(self::PROVIDERS[$provider])) {
            throw new AvailabilityException('ACCOUNTING_PROVIDER', "Unknown accounting provider {$provider}.");
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
