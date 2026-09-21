<?php

namespace App\Services;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Exactly-once execution guard for POSTs, webhooks and jobs.
 *
 * Claim the (scope, key) pair before doing side effects. A replayed key
 * returns the stored response instead of re-executing; an in-progress
 * key returns 409; a key reused with a different payload returns 422.
 */
class IdempotencyService
{
    /**
     * @param  Closure(): mixed  $work
     * @param  array<string, mixed>|null  $requestHash
     * @return array{replayed: bool, response: mixed}
     */
    public function run(
        string $scope,
        string $key,
        Closure $work,
        ?int $branchId = null,
        ?array $requestHash = null,
        int $lockTtlSeconds = 300,
    ): array {
        $record = $this->claim($scope, $key, $branchId, $requestHash, $lockTtlSeconds);

        if ($record->isCompleted()) {
            return ['replayed' => true, 'response' => $record->response];
        }

        try {
            $response = $work();

            $record->update([
                'status' => IdempotencyKey::STATUS_COMPLETED,
                'response' => $this->serializable($response),
            ]);

            return ['replayed' => false, 'response' => $response];
        } catch (\Throwable $e) {
            $record->update(['status' => IdempotencyKey::STATUS_FAILED]);

            throw $e;
        }
    }

    /**
     * Claim a key without executing work. Returns true when the caller
     * owns the claim, false when the key was already completed (replay).
     *
     * @param  array<string, mixed>|null  $requestHash
     *
     * @throws ConflictHttpException
     * @throws UnprocessableEntityHttpException
     */
    public function claimOnly(
        string $scope,
        string $key,
        ?int $branchId = null,
        ?array $requestHash = null,
        int $lockTtlSeconds = 300,
    ): IdempotencyKey {
        return $this->claim($scope, $key, $branchId, $requestHash, $lockTtlSeconds);
    }

    public function markCompleted(IdempotencyKey $record, mixed $response = null): void
    {
        $record->update([
            'status' => IdempotencyKey::STATUS_COMPLETED,
            'response' => $this->serializable($response),
        ]);
    }

    public function markFailed(IdempotencyKey $record): void
    {
        $record->update(['status' => IdempotencyKey::STATUS_FAILED]);
    }

    /**
     * @param  array<string, mixed>|null  $requestHash
     */
    private function claim(
        string $scope,
        string $key,
        ?int $branchId,
        ?array $requestHash,
        int $lockTtlSeconds,
    ): IdempotencyKey {
        return DB::transaction(function () use ($scope, $key, $branchId, $requestHash, $lockTtlSeconds) {
            // Serialize claimants per (scope, key). PostgreSQL aborts the
            // whole transaction on a caught unique violation, so the race
            // is removed up-front with an advisory lock instead of
            // relying on INSERT ... ON CONFLICT handling.
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))', [$scope, $key]);
            }

            $record = IdempotencyKey::where('scope', $scope)
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            if (! $record) {
                try {
                    return IdempotencyKey::create([
                        'branch_id' => $branchId,
                        'scope' => $scope,
                        'key' => $key,
                        'status' => IdempotencyKey::STATUS_IN_PROGRESS,
                        'request_hash' => $requestHash,
                        'locked_until' => now()->addSeconds($lockTtlSeconds),
                    ]);
                } catch (QueryException $e) {
                    // Non-PostgreSQL drivers can still race here; re-read.
                    if (! $this->isUniqueViolation($e)) {
                        throw $e;
                    }

                    /** @var IdempotencyKey $record */
                    $record = IdempotencyKey::where('scope', $scope)
                        ->where('key', $key)
                        ->lockForUpdate()
                        ->firstOrFail();
                }
            }

            if ($requestHash !== null && $record->request_hash !== null && $record->request_hash !== $requestHash) {
                throw new UnprocessableEntityHttpException('Idempotency key was already used with a different payload.');
            }

            if ($record->isCompleted()) {
                return $record;
            }

            if ($record->isLocked()) {
                throw new ConflictHttpException('A request with this idempotency key is already in progress.');
            }

            // Stale or failed claim: take it over.
            $record->update([
                'status' => IdempotencyKey::STATUS_IN_PROGRESS,
                'request_hash' => $requestHash ?? $record->request_hash,
                'locked_until' => now()->addSeconds($lockTtlSeconds),
            ]);

            return $record->fresh() ?? $record;
        });
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->getPrevious()?->getCode();

        return $sqlState === '23000' || $sqlState === '23505';
    }

    /**
     * Normalize a work result for the json response column. Arrays are
     * re-keyed as string-keyed maps because json columns require them.
     *
     * @return array<string, mixed>|string|int|float|bool|null
     */
    private function serializable(mixed $response): array|string|int|float|bool|null
    {
        if ($response === null || is_scalar($response)) {
            return $response;
        }

        if (is_array($response)) {
            return $this->stringKeyed($response);
        }

        if ($response instanceof \JsonSerializable) {
            $encoded = $response->jsonSerialize();

            return is_array($encoded) ? $this->stringKeyed($encoded) : ['value' => $encoded];
        }

        if (is_object($response) && method_exists($response, 'toArray')) {
            $array = $response->toArray();

            return is_array($array) ? $this->stringKeyed($array) : ['value' => $array];
        }

        if (is_object($response) && method_exists($response, '__toString')) {
            return ['value' => $response->__toString()];
        }

        $json = json_encode($response);

        return ['value' => $json === false ? get_debug_type($response) : $json];
    }

    /**
     * @param  array<mixed>  $array
     * @return array<string, mixed>
     */
    private function stringKeyed(array $array): array
    {
        $normalized = [];

        foreach ($array as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }
}
