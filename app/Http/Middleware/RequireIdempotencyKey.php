<?php

namespace App\Http\Middleware;

use App\Services\IdempotencyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Enforces X-Idempotency-Key on mutating web requests.
 *
 * JSON requests get full replay semantics (stored response returned).
 * Inertia/HTML requests get duplicate suppression: a replayed key is
 * rejected with 409 instead of re-executing the side effect.
 */
class RequireIdempotencyKey
{
    /**
     * Route names that never require a key (auth, verification, webhooks
     * derive their own server-side keys, health checks are read-only).
     *
     * @var list<string>
     */
    private array $exempt = [
        'login',
        'logout',
        'register',
        'password.*',
        'verification.*',
        'two-factor.*',
        'passkeys.*',
        'health',
        'up',
        'api.webhooks.*',
        'guest.payment.callback',
        'tablet.menu',
    ];

    public function __construct(private IdempotencyService $idempotency) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || $this->isExempt($request)) {
            return $next($request);
        }

        $key = $request->header('X-Idempotency-Key');

        if (! is_string($key) || trim($key) === '') {
            abort(422, 'An X-Idempotency-Key header is required for this request.');
        }

        $scope = 'web.'.($request->route()?->getName() ?: $request->path());
        $branchId = $request->user()?->branch_id;

        $record = $this->idempotency->claimOnly(
            scope: $scope,
            key: trim($key),
            branchId: is_numeric($branchId) ? (int) $branchId : null,
            requestHash: $this->requestHash($request),
        );

        if ($record->isCompleted()) {
            if ($request->expectsJson()) {
                $stored = is_array($record->response) ? $record->response : [];
                $status = is_int($stored['status'] ?? null) ? $stored['status'] : 200;
                $body = is_array($stored['body'] ?? null) ? $stored['body'] : [];

                return response()->json(array_merge(['replayed' => true], $body), $status)
                    ->header('Idempotent-Replayed', 'true');
            }

            throw new ConflictHttpException('This request was already processed and will not be executed again.');
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $this->idempotency->markFailed($record);

            throw $e;
        }

        if ($response->isSuccessful() || $response->isRedirection()) {
            $stored = $request->expectsJson() ? $this->responsePayload($response) : ['status' => $response->getStatusCode()];
            $this->idempotency->markCompleted($record->fresh() ?? $record, $stored);
        } else {
            $this->idempotency->markFailed($record->fresh() ?? $record);
        }

        return $response;
    }

    private function isExempt(Request $request): bool
    {
        $routeName = $request->route()?->getName() ?? '';

        foreach ($this->exempt as $pattern) {
            if (fnmatch($pattern, $routeName) || $request->is($pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestHash(Request $request): array
    {
        return [
            'method' => $request->method(),
            'path' => $request->path(),
            'body' => hash('sha256', (string) $request->getContent()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function responsePayload(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : null];
    }
}
