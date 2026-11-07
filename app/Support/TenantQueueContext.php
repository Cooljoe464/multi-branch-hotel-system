<?php

namespace App\Support;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TenantQueueContext
{
    /**
     * @var array<int, array{connection: Connection, base_level: int, previous_context: array{user_id: ?string, tenant_id: ?string, actor_type: string, is_global_admin: bool, branch_id: ?string, login_email: ?string}}>
     */
    private array $activeScopes = [];

    public function begin(Job $job): void
    {
        $payload = $job->payload();
        $tenantContext = $payload['tenant_context'] ?? null;
        $tenantId = is_array($tenantContext)
            ? filter_var($tenantContext['tenant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;

        if (! is_int($tenantId)) {
            return;
        }

        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $scopeKey = spl_object_id($job);

        if (isset($this->activeScopes[$scopeKey])) {
            throw new \LogicException('A queue job already has an active tenant context.');
        }

        $baseLevel = $connection->transactionLevel();
        $previousContext = app(TenantDatabaseContext::class)->captureContextSnapshot();
        $connection->beginTransaction();

        try {
            $branchId = filter_var($tenantContext['branch_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            app(TenantDatabaseContext::class)->setQueueContext($tenantId, is_int($branchId) ? $branchId : null);
            $this->activeScopes[$scopeKey] = [
                'connection' => $connection,
                'base_level' => $baseLevel,
                'previous_context' => $previousContext,
            ];
        } catch (Throwable $exception) {
            $connection->rollBack(toLevel: $baseLevel);

            throw $exception;
        }
    }

    public function commit(Job $job): void
    {
        $this->finish($job, true);
    }

    public function rollback(Job $job): void
    {
        $this->finish($job, false);
    }

    private function finish(Job $job, bool $commit): void
    {
        $scopeKey = spl_object_id($job);
        $scope = $this->activeScopes[$scopeKey] ?? null;

        if (! $scope) {
            return;
        }

        $connection = $scope['connection'];
        $baseLevel = $scope['base_level'];
        $expectedLevel = $baseLevel + 1;

        if ($connection->transactionLevel() > $expectedLevel) {
            while ($connection->transactionLevel() > $expectedLevel) {
                $connection->rollBack();
            }

            Log::warning('Queue job left an open database transaction; tenant-scoped work was rolled back.', [
                'job_id' => $job->getJobId(),
            ]);
            $commit = false;
        }

        if ($connection->transactionLevel() !== $expectedLevel) {
            unset($this->activeScopes[$scopeKey]);

            return;
        }

        if ($commit) {
            try {
                if ($baseLevel > 0) {
                    app(TenantDatabaseContext::class)->restoreContextSnapshot($scope['previous_context']);
                }

                $connection->commit();
            } catch (Throwable $exception) {
                while ($connection->transactionLevel() > $baseLevel) {
                    $connection->rollBack();
                }

                unset($this->activeScopes[$scopeKey]);

                throw $exception;
            }

            unset($this->activeScopes[$scopeKey]);

            return;
        }

        $connection->rollBack();
        unset($this->activeScopes[$scopeKey]);
    }
}
