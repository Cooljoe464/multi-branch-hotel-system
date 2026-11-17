<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\Branch;
use App\Services\EntitlementService;
use App\Services\PublicApiKeyService;
use App\Services\PublicApiUsageService;
use App\Support\TenantDatabaseContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePublicApiKey
{
    public function __construct(
        private PublicApiKeyService $keys,
        private TenantDatabaseContext $tenantContext,
        private EntitlementService $entitlements,
        private PublicApiUsageService $usage,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plainTextKey = $request->header('X-API-Key');
        $isPreflight = $request->isMethod('OPTIONS');

        // Browsers omit X-API-Key from the preflight itself. A publishable key
        // in the URL identifies the per-key origin policy without exposing any
        // server-side secret; the actual request still uses the header.
        if ($isPreflight && (! is_string($plainTextKey) || trim($plainTextKey) === '')) {
            $plainTextKey = $request->query('publishable_key');
        }

        if (! is_string($plainTextKey) || trim($plainTextKey) === '') {
            abort(401, 'A public API key is required.');
        }

        $key = $this->keys->resolve(trim($plainTextKey));

        if (! $key instanceof ApiKey || ! $key->usable() || ! $key->acceptsHash(hash('sha256', trim($plainTextKey)))) {
            abort(401, 'Invalid public API key.');
        }

        if ($isPreflight && $key->type !== 'publishable') {
            abort(401, 'A publishable API key is required for browser preflight.');
        }

        $origin = $request->headers->get('Origin');
        if ($key->type === 'secret' && is_string($origin) && $origin !== '') {
            abort(403, 'Secret API keys are for server-to-server requests only.');
        }

        if (is_string($origin) && $origin !== '' && (! is_array($key->allowed_origins) || ! in_array($origin, $key->allowed_origins, true))) {
            abort(403, 'The request origin is not allowed for this API key.');
        }

        if (is_array($key->ip_allowlist) && $key->ip_allowlist !== [] && ! $this->ipAllowed($request->ip(), $key->ip_allowlist)) {
            abort(403, 'The request IP is not allowed for this API key.');
        }

        $rateLimit = max(1, config()->integer('public_api.requests_per_minute'));
        $rateLimitKey = 'public-api:key:'.$key->id;

        if (RateLimiter::tooManyAttempts($rateLimitKey, $rateLimit)) {
            $retryAfter = RateLimiter::availableIn($rateLimitKey);

            return $this->withCors(
                response()->json(['message' => 'The API key request limit has been reached.'], 429)
                    ->header('Retry-After', (string) $retryAfter),
                $origin,
                $key,
            );
        }

        RateLimiter::hit($rateLimitKey, 60);

        $branchId = $key->branch_id;
        $tenantId = $key->tenant_id;
        $response = $this->tenantContext->run(null, $tenantId, function () use ($request, $next, $key, $branchId, $tenantId): Response {
            $this->tenantContext->setBranchId($branchId);
            $branch = Branch::query()->whereKey($branchId)->where('tenant_id', $tenantId)->firstOrFail();
            abort_unless($branch->is_active, 403, 'The API key property is inactive.');

            $key->forceFill(['last_used_at' => now()])->saveQuietly();
            $request->attributes->set('public_api_key', $key);
            $request->attributes->set('branch', $branch);

            if (! $this->entitlements->allows($tenantId, 'api.public')) {
                return response()->json(['message' => 'The public API is not enabled for this property.', 'entitlement' => 'api.public'], 403);
            }

            if ($request->isMethod('OPTIONS')) {
                return response()->noContent();
            }

            $retryAfter = $this->usage->consume($key, $branch);
            if ($retryAfter !== null) {
                return response()->json(['message' => 'The daily API request allowance has been reached.'], 429)
                    ->header('Retry-After', (string) $retryAfter);
            }

            return $next($request);
        }, actorType: 'public_api');

        abort_unless($response instanceof Response, 500);

        return $this->withCors($response, $origin, $key);
    }

    /** @param  list<string>  $allowlist */
    private function ipAllowed(?string $ip, array $allowlist): bool
    {
        if (! is_string($ip)) {
            return false;
        }

        foreach ($allowlist as $allowed) {
            if (filter_var($allowed, FILTER_VALIDATE_IP) === $ip || (str_contains($allowed, '/') && IpUtils::checkIp($ip, $allowed))) {
                return true;
            }
        }

        return false;
    }

    private function withCors(Response $response, ?string $origin, ApiKey $key): Response
    {
        if (! is_string($origin) || $origin === '' || ! is_array($key->allowed_origins) || ! in_array($origin, $key->allowed_origins, true)) {
            return $response;
        }

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-API-Key, X-Idempotency-Key');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS');
        $response->headers->set('Vary', 'Origin');

        return $response;
    }
}
