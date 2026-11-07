<?php

namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

class TenantDatabaseContext
{
    /**
     * @return array{user_id: ?string, tenant_id: ?string, actor_type: string, is_global_admin: bool, branch_id: ?string, login_email: ?string}
     */
    public function captureContextSnapshot(): array
    {
        return $this->currentContext(DB::connection());
    }

    /**
     * @param  array{user_id: ?string, tenant_id: ?string, actor_type: string, is_global_admin: bool, branch_id: ?string, login_email: ?string}  $context
     */
    public function restoreContextSnapshot(array $context): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql' || $connection->transactionLevel() === 0) {
            throw new \LogicException('Tenant context can only be restored inside a PostgreSQL transaction.');
        }

        $this->setContext(
            $connection,
            $context['user_id'],
            $context['tenant_id'],
            $context['actor_type'],
            $context['is_global_admin'],
            $context['branch_id'],
            $context['login_email'],
        );
    }

    /**
     * @return array{tenant_id: int, branch_id: ?int}|null
     */
    public function captureQueueContext(): ?array
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql' || $connection->transactionLevel() === 0) {
            return null;
        }

        $context = $this->currentContext($connection);
        $tenantId = filter_var($context['tenant_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (! is_int($tenantId)) {
            return null;
        }

        $branchId = filter_var($context['branch_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return [
            'tenant_id' => $tenantId,
            'branch_id' => is_int($branchId) ? $branchId : null,
        ];
    }

    public function setQueueContext(int $tenantId, ?int $branchId): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        if ($connection->transactionLevel() === 0) {
            throw new \LogicException('Queue tenant context can only change inside a database transaction.');
        }

        $this->setContext($connection, null, $tenantId, 'queue', branchId: $branchId === null ? null : (string) $branchId);
    }

    public function run(
        ?int $userId,
        ?int $tenantId,
        callable $callback,
        string $actorType = 'user',
        bool $isGlobalAdmin = false,
        ?string $loginEmail = null,
    ): mixed {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return $callback();
        }

        $previousContext = $this->currentContext($connection);
        $connection->beginTransaction();

        try {
            $this->setContext($connection, $userId, $tenantId, $actorType, $isGlobalAdmin, loginEmail: $loginEmail);
            $result = $callback();

            if ($connection->transactionLevel() > 1) {
                $this->setContext(
                    $connection,
                    $previousContext['user_id'],
                    $previousContext['tenant_id'],
                    $previousContext['actor_type'],
                    $previousContext['is_global_admin'],
                    $previousContext['branch_id'],
                    loginEmail: $previousContext['login_email'],
                );
            }

            $connection->commit();

            return $result;
        } catch (Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }

    public function setTenantId(?int $tenantId): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        if ($connection->transactionLevel() === 0) {
            throw new \LogicException('Tenant context can only change inside a database transaction.');
        }

        $this->setContext($connection, null, $tenantId, preserveUserId: true);
    }

    public function setBranchId(?int $branchId): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        if ($connection->transactionLevel() === 0) {
            throw new \LogicException('Branch context can only change inside a database transaction.');
        }

        $connection->select('select set_config(?, ?, true)', [
            'app.branch_id',
            (string) ($branchId ?? ''),
        ]);
    }

    /**
     * @return array{user_id: ?string, tenant_id: ?string, actor_type: string, is_global_admin: bool, branch_id: ?string, login_email: ?string}
     */
    private function currentContext(Connection $connection): array
    {
        $context = $connection->selectOne("select current_setting('app.user_id', true) as user_id, current_setting('app.tenant_id', true) as tenant_id, current_setting('app.actor_type', true) as actor_type, current_setting('app.is_global_admin', true) as is_global_admin, current_setting('app.branch_id', true) as branch_id, current_setting('app.login_email', true) as login_email");

        return [
            'user_id' => $context->user_id === '' ? null : $context->user_id,
            'tenant_id' => $context->tenant_id === '' ? null : $context->tenant_id,
            'actor_type' => is_string($context->actor_type) && $context->actor_type !== '' ? $context->actor_type : 'user',
            'is_global_admin' => in_array($context->is_global_admin, [true, 1, '1', 't', 'true'], true),
            'branch_id' => is_string($context->branch_id) && $context->branch_id !== '' ? $context->branch_id : null,
            'login_email' => is_string($context->login_email) && $context->login_email !== '' ? $context->login_email : null,
        ];
    }

    private function setContext(
        Connection $connection,
        int|string|null $userId,
        int|string|null $tenantId,
        string $actorType = 'user',
        bool $isGlobalAdmin = false,
        ?string $branchId = null,
        ?string $loginEmail = null,
        bool $preserveUserId = false,
    ): void {
        if ($preserveUserId) {
            $connection->select('select set_config(?, ?, true)', ['app.tenant_id', (string) ($tenantId ?? '')]);

            return;
        }

        $connection->select('select set_config(?, ?, true), set_config(?, ?, true), set_config(?, ?, true), set_config(?, ?, true), set_config(?, ?, true), set_config(?, ?, true)', [
            'app.user_id',
            (string) ($userId ?? ''),
            'app.tenant_id',
            (string) ($tenantId ?? ''),
            'app.actor_type',
            $actorType,
            'app.is_global_admin',
            $isGlobalAdmin ? 'true' : 'false',
            'app.branch_id',
            (string) ($branchId ?? ''),
            'app.login_email',
            (string) ($loginEmail ?? ''),
        ]);
    }
}
