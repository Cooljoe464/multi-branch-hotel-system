<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Monthly partition provisioning for activity_log and transactions.
 * journal_entries keeps its single-column primary key with no
 * inbound foreign keys pending the same treatment; readiness()
 * names every remaining blocker.
 */
class PartitionManager
{
    /**
     * @return list<string> created partition names
     */
    public function ensureFuturePartitions(): array
    {
        $created = [];
        $currentMonth = new \DateTimeImmutable('first day of this month');
        $migrationConnection = DB::connection('migration');
        $migrationSchema = $migrationConnection->getSchemaBuilder();

        if ($migrationSchema->hasTable('activity_log')) {
            foreach ([0, 1, 2] as $offset) {
                $start = $currentMonth->modify("+{$offset} months");
                $name = 'activity_log_'.strtolower($start->format('Y_M'));
                $end = $start->modify('+1 month');

                $migrationConnection->statement(
                    "CREATE TABLE IF NOT EXISTS {$name} PARTITION OF activity_log ".
                    "FOR VALUES FROM ('".$start->format('Y-m-d')."') TO ('".$end->format('Y-m-d')."')"
                );
                $this->enforceActivityPartitionRls($name);
                $created[] = $name;
            }
        }

        if ($this->isPartitioned('transactions')) {
            foreach ([0, 1, 2] as $offset) {
                $start = $currentMonth->modify("+{$offset} months");
                $name = 'transactions_'.$start->format('Y_m');
                $end = $start->modify('+1 month');

                $migrationConnection->statement(
                    "CREATE TABLE IF NOT EXISTS {$name} PARTITION OF transactions ".
                    "FOR VALUES FROM ('".$start->format('Y-m-d')."') TO ('".$end->format('Y-m-d')."')"
                );
                $created[] = $name;
            }
        }

        return $created;
    }

    private function enforceActivityPartitionRls(string $partitionName): void
    {
        $connection = DB::connection('migration');
        $parent = $connection->selectOne(
            "SELECT relrowsecurity FROM pg_class WHERE oid = 'public.activity_log'::regclass",
        );

        if (! in_array($parent->relrowsecurity ?? false, [true, 1, '1', 't', 'true'], true)) {
            return;
        }

        $partition = $connection->getQueryGrammar()->wrapTable('public.'.$partitionName);
        $tenantExpression = "tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint";
        $globalExpression = "tenant_id IS NULL AND current_setting('app.is_global_admin', true) = 'true'";
        $policyExpression = "{$tenantExpression} OR ({$globalExpression})";

        $connection->statement("ALTER TABLE {$partition} ENABLE ROW LEVEL SECURITY");
        $connection->statement("ALTER TABLE {$partition} FORCE ROW LEVEL SECURITY");
        $connection->statement("DROP POLICY IF EXISTS hms_tenant_isolation ON {$partition}");
        $connection->statement(
            "CREATE POLICY hms_tenant_isolation ON {$partition} FOR ALL "
            ."USING ({$policyExpression}) WITH CHECK ({$policyExpression})"
        );
    }

    /**
     * @return array<string, array{partitioned: bool, partitions: int, blockers: list<string>}>
     */
    public function readiness(): array
    {
        $report = [];

        foreach (['activity_log', 'transactions', 'journal_entries'] as $table) {
            $report[$table] = [
                'partitioned' => $this->isPartitioned($table),
                'partitions' => $this->partitionCount($table),
                'blockers' => $this->blockers($table),
            ];
        }

        return $report;
    }

    public function isPartitioned(string $table): bool
    {
        try {
            $row = DB::selectOne(
                'SELECT relkind FROM pg_class WHERE relname = ?',
                [$table]
            );

            return is_object($row) && ($row->relkind ?? null) === 'p';
        } catch (\Throwable) {
            return false;
        }
    }

    public function partitionCount(string $table): int
    {
        try {
            $rows = DB::select(
                'SELECT c.relname FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid '.
                'WHERE i.inhparent = ?::regclass',
                [$table]
            );

            return count($rows);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return list<string>
     */
    private function blockers(string $table): array
    {
        if ($this->isPartitioned($table)) {
            return [];
        }

        try {
            $rows = DB::select(
                'SELECT conname FROM pg_constraint '.
                'WHERE confrelid = ?::regclass AND contype = \'f\'',
                [$table]
            );

            return array_values(array_filter(array_map(
                fn ($row) => is_object($row) && isset($row->conname) && is_string($row->conname)
                    ? "referenced by foreign key {$row->conname}"
                    : null,
                $rows
            )));
        } catch (\Throwable) {
            return ['inspection unavailable'];
        }
    }
}
