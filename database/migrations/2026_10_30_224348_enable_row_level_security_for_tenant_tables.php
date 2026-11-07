<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Tenant row-level security requires PostgreSQL.');
        }

        foreach ($this->tenantTables() as $tableName) {
            $this->enableRls($tableName);
        }
    }

    public function down(): void
    {
        foreach ($this->tenantTables() as $tableName) {
            $table = $this->wrappedTable($tableName);

            DB::statement("DROP POLICY IF EXISTS hms_tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }

    /**
     * @return list<string>
     */
    private function tenantTables(): array
    {
        $tenantOwnedTables = DB::table('information_schema.columns')
            ->join('information_schema.tables', function ($join): void {
                $join->on('information_schema.tables.table_schema', '=', 'information_schema.columns.table_schema')
                    ->on('information_schema.tables.table_name', '=', 'information_schema.columns.table_name');
            })
            ->where('information_schema.columns.table_schema', 'public')
            ->where('information_schema.tables.table_type', 'BASE TABLE')
            ->where('information_schema.columns.column_name', 'tenant_id')
            ->orderBy('information_schema.columns.table_name')
            ->distinct()
            ->pluck('information_schema.columns.table_name')
            ->all();

        $tables = array_values(array_unique(array_merge(
            $tenantOwnedTables,
            ['tenants', 'branches', 'users', 'user_branch'],
        )));

        return array_values(array_filter($tables, fn (string $tableName): bool => Schema::hasTable($tableName)));
    }

    private function enableRls(string $tableName): void
    {
        $table = $this->wrappedTable($tableName);
        $tenant = "NULLIF(current_setting('app.tenant_id', true), '')::bigint";
        $user = "NULLIF(current_setting('app.user_id', true), '')::bigint";
        $globalAdmin = "current_setting('app.is_global_admin', true) = 'true'";

        $policy = match ($tableName) {
            'tenants' => $this->tenantPolicy($table, "id = {$tenant} OR {$globalAdmin}"),
            'branches' => $this->branchPolicy($table, $tenant, $user, $globalAdmin),
            'user_branch' => $this->userBranchPolicy($table, $tenant, $user, $globalAdmin),
            'users' => $this->userPolicy($table, $tenant, $user, $globalAdmin),
            'api_consumers' => $this->apiConsumerPolicy($table, $tenant, $user, $globalAdmin),
            default => $this->tenantPolicy($table, "tenant_id = {$tenant} OR (tenant_id IS NULL AND {$globalAdmin})"),
        };

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS hms_tenant_isolation ON {$table}");
        DB::statement($policy);
    }

    private function tenantPolicy(string $table, string $expression): string
    {
        return "CREATE POLICY hms_tenant_isolation ON {$table} FOR ALL USING ({$expression}) WITH CHECK ({$expression})";
    }

    private function branchPolicy(string $table, string $tenant, string $user, string $globalAdmin): string
    {
        $readExpression = "tenant_id = {$tenant} OR {$globalAdmin} OR (current_setting('app.actor_type', true) = 'api_consumer' AND id = NULLIF(current_setting('app.branch_id', true), '')::bigint) OR EXISTS (SELECT 1 FROM public.user_branch AS membership WHERE membership.branch_id = branches.id AND membership.user_id = {$user})";
        $writeExpression = "tenant_id = {$tenant} OR {$globalAdmin}";

        return "CREATE POLICY hms_tenant_isolation ON {$table} FOR ALL USING ({$readExpression}) WITH CHECK ({$writeExpression})";
    }

    private function userBranchPolicy(string $table, string $tenant, string $user, string $globalAdmin): string
    {
        $readExpression = "tenant_id = {$tenant} OR user_id = {$user} OR {$globalAdmin}";
        $writeExpression = "tenant_id = {$tenant} OR {$globalAdmin}";

        return "CREATE POLICY hms_tenant_isolation ON {$table} FOR ALL USING ({$readExpression}) WITH CHECK ({$writeExpression})";
    }

    private function userPolicy(string $table, string $tenant, string $user, string $globalAdmin): string
    {
        $branchInTenant = "EXISTS (SELECT 1 FROM public.branches AS scoped_branch WHERE scoped_branch.id = users.branch_id AND scoped_branch.tenant_id = {$tenant})";
        $membershipInTenant = "EXISTS (SELECT 1 FROM public.user_branch AS scoped_membership WHERE scoped_membership.user_id = users.id AND scoped_membership.tenant_id = {$tenant})";
        $readExpression = "id = {$user} OR {$globalAdmin} OR {$branchInTenant} OR {$membershipInTenant}";
        $writeExpression = "{$globalAdmin} OR {$branchInTenant} OR (id = {$user} AND (branch_id IS NULL OR {$branchInTenant}))";

        return "CREATE POLICY hms_tenant_isolation ON {$table} FOR ALL USING ({$readExpression}) WITH CHECK ({$writeExpression})";
    }

    private function apiConsumerPolicy(string $table, string $tenant, string $user, string $globalAdmin): string
    {
        $readExpression = "tenant_id = {$tenant} OR (current_setting('app.actor_type', true) = 'api_consumer' AND id = {$user}) OR (tenant_id IS NULL AND {$globalAdmin})";
        $writeExpression = "tenant_id = {$tenant} OR (tenant_id IS NULL AND {$globalAdmin})";

        return "CREATE POLICY hms_tenant_isolation ON {$table} FOR ALL USING ({$readExpression}) WITH CHECK ({$writeExpression})";
    }

    private function wrappedTable(string $tableName): string
    {
        return DB::connection()->getQueryGrammar()->wrapTable('public.'.$tableName);
    }
};
