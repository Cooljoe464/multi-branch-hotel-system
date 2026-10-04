<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DBA maintenance-window cutover: converts transactions to monthly
 * RANGE partitions on business_date. NEVER runs in normal deploys —
 * invoke explicitly during a window with traffic drained.
 *
 * Preconditions (checked, not assumed): business_date NOT NULL
 * everywhere, every linked child row carries its parent's date, and
 * no orphan links exist. The self-referencing transfer FK is dropped
 * to app-level enforcement because transfers routinely span dates;
 * transferToMaster() already guards idempotency in code.
 */
class PartitionTransactions extends Command
{
    protected $signature = 'db:partition-transactions
        {--dry-run : Report what would change without touching the schema}
        {--rollback : Reverse a previous cutover (plain table, original FKs)}
        {--batch=50000 : Copy batch size in rows}';

    protected $description = 'Convert transactions to monthly partitions (maintenance window only).';

    /**
     * @var list<string>
     */
    private const COLUMNS = [
        'id', 'folio_id', 'business_date', 'type', 'category', 'description',
        'amount', 'reference_type', 'reference_id', 'posted_by', 'is_taxable',
        'tax_amount', 'is_voided', 'voided_at', 'metadata', 'created_at',
        'updated_at', 'currency_code', 'idempotency_key', 'tax_snapshot',
        'tax_total_minor', 'folio_window_id', 'group_master_folio_id',
        'transfer_of_transaction_id', 'void_reason_code_id', 'cashier_shift_id',
    ];

    public function handle(): int
    {
        if ($this->option('rollback')) {
            return $this->rollback();
        }

        if ($this->isPartitioned()) {
            $this->info('transactions is already partitioned. Nothing to do.');

            return 0;
        }

        $blockers = $this->preconditions();

        if ($blockers !== []) {
            foreach ($blockers as $blocker) {
                $this->error($blocker);
            }

            return 1;
        }

        if ($this->option('dry-run')) {
            $this->report();

            return 0;
        }

        $this->cutover((int) $this->option('batch'));

        return 0;
    }

    /**
     * @param  list<mixed>  $bindings
     */
    private function scalarCount(string $sql, array $bindings = []): int
    {
        $row = DB::selectOne($sql, $bindings);
        $value = is_object($row) ? ($row->c ?? null) : null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  list<mixed>  $bindings
     */
    private function scalarString(string $sql, string $key, array $bindings = []): ?string
    {
        $row = DB::selectOne($sql, $bindings);
        $value = is_object($row) ? ($row->{$key} ?? null) : null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  list<mixed>  $bindings
     */
    private function scalarInt(string $sql, string $key, array $bindings = []): int
    {
        $row = DB::selectOne($sql, $bindings);
        $value = is_object($row) ? ($row->{$key} ?? null) : null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return list<string> blocking reasons, empty when ready
     */
    private function preconditions(): array
    {
        $blockers = [];

        if (! Schema::hasColumn('transactions', 'business_date')) {
            return ['transactions.business_date is missing; run migrations first.'];
        }

        $nullParents = $this->scalarCount('SELECT count(*) AS c FROM transactions WHERE business_date IS NULL');

        if ($nullParents > 0) {
            $blockers[] = "{$nullParents} transactions rows lack business_date.";
        }

        foreach (['pos_charges', 'folio_disputes', 'transaction_splits'] as $table) {
            if (! Schema::hasColumn($table, 'business_date')) {
                $blockers[] = "{$table}.business_date is missing; run migrations first.";

                continue;
            }

            $stale = $this->scalarCount(
                "SELECT count(*) AS c FROM {$table} c LEFT JOIN transactions t ON t.id = c.transaction_id ".
                'WHERE c.transaction_id IS NOT NULL AND (c.business_date IS NULL OR t.id IS NULL OR c.business_date IS DISTINCT FROM t.business_date)'
            );

            if ($stale > 0) {
                $blockers[] = "{$stale} {$table} rows are orphaned or date-mismatched.";
            }
        }

        return $blockers;
    }

    private function report(): void
    {
        $total = $this->scalarCount('SELECT count(*) AS c FROM transactions');
        $lo = $this->scalarString('SELECT min(business_date) AS lo FROM transactions', 'lo');
        $hi = $this->scalarString('SELECT max(business_date) AS hi FROM transactions', 'hi');

        $this->info("transactions rows: {$total}");
        $this->info('date range: '.($lo ?? 'empty').' .. '.($hi ?? 'empty'));
        $this->info('months to provision: '.count($this->months()));
        $this->info('FKs to drop: 4 (3 inbound + 1 self-reference). Composite FKs to add: 3.');
        $this->info('Outbound FKs + 7 indexes recreated on the partitioned parent.');
    }

    /**
     * @return list<string> month starts Y-m-d
     */
    private function months(): array
    {
        $lo = $this->scalarString('SELECT min(business_date) AS lo FROM transactions', 'lo');

        $start = $lo !== null
            ? new \DateTimeImmutable($lo)
            : new \DateTimeImmutable('first day of this month');
        $start = $start->modify('first day of this month');

        $end = (new \DateTimeImmutable('first day of this month'))->modify('+3 month');

        $months = [];
        for ($cursor = $start; $cursor < $end; $cursor = $cursor->modify('+1 month')) {
            $months[] = $cursor->format('Y-m-d');
        }

        return $months;
    }

    private function cutover(int $batch): void
    {
        DB::statement('LOCK TABLE transactions, pos_charges, folio_disputes, transaction_splits IN ACCESS EXCLUSIVE MODE');

        foreach ([
            'pos_charges_transaction_id_foreign ON pos_charges',
            'folio_disputes_transaction_id_foreign ON folio_disputes',
            'transaction_splits_transaction_id_foreign ON transaction_splits',
            'transactions_transfer_of_transaction_id_foreign ON transactions',
        ] as $spec) {
            [$constraint, $table] = explode(' ON ', $spec);
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
        }

        $columns = implode(', ', self::COLUMNS);

        DB::statement(<<<'SQL'
            CREATE TABLE transactions_new (
                id bigint NOT NULL DEFAULT nextval('transactions_id_seq'::regclass),
                folio_id bigint NOT NULL,
                business_date date NOT NULL,
                type varchar(255) NOT NULL,
                category varchar(255) NOT NULL,
                description varchar(255) NOT NULL,
                amount integer NOT NULL,
                reference_type varchar(255),
                reference_id bigint,
                posted_by bigint,
                is_taxable boolean NOT NULL DEFAULT true,
                tax_amount integer NOT NULL DEFAULT 0,
                is_voided boolean NOT NULL DEFAULT false,
                voided_at timestamp(0) without time zone,
                metadata json,
                created_at timestamp(0) without time zone,
                updated_at timestamp(0) without time zone,
                currency_code varchar(3) NOT NULL DEFAULT 'NGN'::character varying,
                idempotency_key varchar(64),
                tax_snapshot json,
                tax_total_minor integer NOT NULL DEFAULT 0,
                folio_window_id bigint,
                group_master_folio_id bigint,
                transfer_of_transaction_id bigint,
                void_reason_code_id bigint,
                cashier_shift_id bigint,
                PRIMARY KEY (business_date, id)
            ) PARTITION BY RANGE (business_date)
            SQL);

        foreach ($this->months() as $month) {
            $this->ensurePartition('transactions_new', $month);
        }

        $minId = $this->scalarInt('SELECT coalesce(min(id), 0) AS m FROM transactions', 'm');
        $maxId = $this->scalarInt('SELECT coalesce(max(id), 0) AS m FROM transactions', 'm');

        for ($from = $minId; $from <= $maxId; $from += $batch) {
            $to = $from + $batch - 1;
            DB::statement("INSERT INTO transactions_new ({$columns}) SELECT {$columns} FROM transactions WHERE id BETWEEN {$from} AND {$to} ORDER BY id");
            $this->info("copied ids {$from}..{$to}");
        }

        $before = $this->scalarCount('SELECT count(*) AS c FROM transactions');
        $after = $this->scalarCount('SELECT count(*) AS c FROM transactions_new');

        if ($before !== $after) {
            throw new \RuntimeException("Row count mismatch after copy ({$before} vs {$after}). Aborting before swap.");
        }

        DB::statement('ALTER TABLE transactions RENAME TO transactions_legacy');
        DB::statement('ALTER TABLE transactions_new RENAME TO transactions');

        foreach ($this->partitionNames('transactions') as $old) {
            DB::statement("ALTER TABLE {$old} RENAME TO ".str_replace('transactions_new', 'transactions', $old));
        }

        DB::statement('ALTER SEQUENCE transactions_id_seq OWNED BY transactions.id');
        DB::statement('DROP TABLE transactions_legacy');

        foreach ([
            'CREATE INDEX transactions_type_index ON transactions (type)',
            'CREATE INDEX transactions_category_index ON transactions (category)',
            'CREATE INDEX transactions_folio_id_index ON transactions (folio_id)',
            'CREATE INDEX transactions_folio_id_business_date_index ON transactions (folio_id, business_date)',
            'CREATE INDEX transactions_reference_type_reference_id_index ON transactions (reference_type, reference_id)',
            'CREATE INDEX transactions_idempotency_key_index ON transactions (idempotency_key)',
            'ALTER TABLE transactions ADD CONSTRAINT transactions_folio_id_foreign FOREIGN KEY (folio_id) REFERENCES folios(id) ON DELETE CASCADE',
            'ALTER TABLE transactions ADD CONSTRAINT transactions_posted_by_foreign FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE SET NULL',
            'ALTER TABLE transactions ADD CONSTRAINT transactions_folio_window_id_foreign FOREIGN KEY (folio_window_id) REFERENCES folio_windows(id) ON DELETE SET NULL',
            'ALTER TABLE transactions ADD CONSTRAINT transactions_group_master_folio_id_foreign FOREIGN KEY (group_master_folio_id) REFERENCES folios(id) ON DELETE SET NULL',
            'ALTER TABLE transactions ADD CONSTRAINT transactions_void_reason_code_id_foreign FOREIGN KEY (void_reason_code_id) REFERENCES void_refund_codes(id) ON DELETE SET NULL',
            'ALTER TABLE transactions ADD CONSTRAINT transactions_cashier_shift_id_foreign FOREIGN KEY (cashier_shift_id) REFERENCES cashier_shifts(id) ON DELETE SET NULL',
            'ALTER TABLE pos_charges ADD CONSTRAINT pos_charges_transaction_composite FOREIGN KEY (business_date, transaction_id) REFERENCES transactions(business_date, id) ON DELETE SET NULL',
            'ALTER TABLE folio_disputes ADD CONSTRAINT folio_disputes_transaction_composite FOREIGN KEY (business_date, transaction_id) REFERENCES transactions(business_date, id) ON DELETE SET NULL',
            'ALTER TABLE transaction_splits ADD CONSTRAINT transaction_splits_transaction_composite FOREIGN KEY (business_date, transaction_id) REFERENCES transactions(business_date, id) ON DELETE CASCADE',
        ] as $ddl) {
            DB::statement($ddl);
        }

        DB::statement('ANALYZE transactions');

        $this->info('transactions partitioned by business_date month.');
    }

    private function rollback(): int
    {
        if (! $this->isPartitioned()) {
            $this->info('transactions is not partitioned. Nothing to roll back.');

            return 0;
        }

        if ($this->option('dry-run')) {
            $this->info('Would restore a plain transactions table and the 4 original single-column FKs.');

            return 0;
        }

        DB::statement('LOCK TABLE transactions, pos_charges, folio_disputes, transaction_splits IN ACCESS EXCLUSIVE MODE');

        foreach ([
            'pos_charges_transaction_composite ON pos_charges',
            'folio_disputes_transaction_composite ON folio_disputes',
            'transaction_splits_transaction_composite ON transaction_splits',
        ] as $spec) {
            [$constraint, $table] = explode(' ON ', $spec);
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
        }

        $columns = implode(', ', self::COLUMNS);

        DB::statement(<<<'SQL'
            CREATE TABLE transactions_plain (
                id bigserial PRIMARY KEY,
                folio_id bigint NOT NULL,
                business_date date NOT NULL,
                type varchar(255) NOT NULL,
                category varchar(255) NOT NULL,
                description varchar(255) NOT NULL,
                amount integer NOT NULL,
                reference_type varchar(255),
                reference_id bigint,
                posted_by bigint,
                is_taxable boolean NOT NULL DEFAULT true,
                tax_amount integer NOT NULL DEFAULT 0,
                is_voided boolean NOT NULL DEFAULT false,
                voided_at timestamp(0) without time zone,
                metadata json,
                created_at timestamp(0) without time zone,
                updated_at timestamp(0) without time zone,
                currency_code varchar(3) NOT NULL DEFAULT 'NGN'::character varying,
                idempotency_key varchar(64),
                tax_snapshot json,
                tax_total_minor integer NOT NULL DEFAULT 0,
                folio_window_id bigint,
                group_master_folio_id bigint,
                transfer_of_transaction_id bigint,
                void_reason_code_id bigint,
                cashier_shift_id bigint
            )
            SQL);

        DB::statement("INSERT INTO transactions_plain ({$columns}) SELECT {$columns} FROM transactions ORDER BY id");
        DB::statement("SELECT setval('transactions_plain_id_seq', (SELECT max(id) FROM transactions_plain))");
        DB::statement('ALTER TABLE transactions RENAME TO transactions_partitioned');
        DB::statement('ALTER TABLE transactions_plain RENAME TO transactions');

        // Indexes travel with the rename; drop the inherited names
        // before recreating them on the plain table.
        foreach ([
            'transactions_type_index',
            'transactions_category_index',
            'transactions_folio_id_index',
            'transactions_folio_id_business_date_index',
            'transactions_reference_type_reference_id_index',
            'transactions_idempotency_key_index',
        ] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }

        foreach ([
            'CREATE INDEX transactions_type_index ON transactions (type)',
            'CREATE INDEX transactions_category_index ON transactions (category)',
            'CREATE INDEX transactions_folio_id_index ON transactions (folio_id)',
            'CREATE INDEX transactions_folio_id_business_date_index ON transactions (folio_id, business_date)',
            'CREATE INDEX transactions_reference_type_reference_id_index ON transactions (reference_type, reference_id)',
            'CREATE INDEX transactions_idempotency_key_index ON transactions (idempotency_key)',
            'ALTER TABLE transactions ADD CONSTRAINT transactions_folio_id_foreign FOREIGN KEY (folio_id) REFERENCES folios(id) ON DELETE CASCADE',
            'ALTER TABLE transactions ADD CONSTRAINT transactions_posted_by_foreign FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE SET NULL',
            'ALTER TABLE transactions ADD CONSTRAINT transactions_folio_window_id_foreign FOREIGN KEY (folio_window_id) REFERENCES folio_windows(id) ON DELETE SET NULL',
            'ALTER TABLE transactions ADD CONSTRAINT transactions_group_master_folio_id_foreign FOREIGN KEY (group_master_folio_id) REFERENCES folios(id) ON DELETE SET NULL',
            'ALTER TABLE transactions ADD CONSTRAINT transactions_void_reason_code_id_foreign FOREIGN KEY (void_reason_code_id) REFERENCES void_refund_codes(id) ON DELETE SET NULL',
            'ALTER TABLE transactions ADD CONSTRAINT transactions_cashier_shift_id_foreign FOREIGN KEY (cashier_shift_id) REFERENCES cashier_shifts(id) ON DELETE SET NULL',
            'ALTER TABLE transactions ADD CONSTRAINT transactions_transfer_of_transaction_id_foreign FOREIGN KEY (transfer_of_transaction_id) REFERENCES transactions(id) ON DELETE SET NULL',
            'ALTER TABLE pos_charges ADD CONSTRAINT pos_charges_transaction_id_foreign FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL',
            'ALTER TABLE folio_disputes ADD CONSTRAINT folio_disputes_transaction_id_foreign FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL',
            'ALTER TABLE transaction_splits ADD CONSTRAINT transaction_splits_transaction_id_foreign FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE',
        ] as $ddl) {
            DB::statement($ddl);
        }

        DB::statement('DROP TABLE transactions_partitioned');

        $this->info('transactions restored to a plain table with original FKs.');

        return 0;
    }

    private function isPartitioned(): bool
    {
        try {
            $row = DB::selectOne('SELECT relkind FROM pg_class WHERE relname = ?', ['transactions']);

            return is_object($row) && ($row->relkind ?? null) === 'p';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function partitionNames(string $parent): array
    {
        $rows = DB::select(
            'SELECT c.relname AS name FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid WHERE i.inhparent = ?::regclass ORDER BY c.relname',
            [$parent]
        );

        $names = [];

        foreach ($rows as $row) {
            if (is_object($row) && isset($row->name) && is_string($row->name)) {
                $names[] = $row->name;
            }
        }

        return $names;
    }

    private function ensurePartition(string $parent, string $monthStart): void
    {
        $start = new \DateTimeImmutable($monthStart);
        $end = $start->modify('+1 month');
        $name = $parent.'_'.$start->format('Y_m');

        DB::statement(
            "CREATE TABLE IF NOT EXISTS {$name} PARTITION OF {$parent} ".
            "FOR VALUES FROM ('".$start->format('Y-m-d')."') TO ('".$end->format('Y-m-d')."')"
        );
    }
}
