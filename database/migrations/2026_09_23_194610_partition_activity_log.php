<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Convert activity_log to monthly RANGE partitions on created_at.
 *
 * Why only activity_log: it has no inbound foreign keys, so the
 * cutover is a lock-copy-swap with zero integrity loss.
 * `transactions` keeps its single id primary key because four child
 * tables reference transactions(id); declarative partitioning would
 * require composite keys and FK rewrites — see PartitionManager
 * readiness and docs/DR-RUNBOOK.md. That cutover needs a DBA
 * maintenance window and is explicitly out of this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            DB::statement('LOCK TABLE activity_log IN ACCESS EXCLUSIVE MODE');

            DB::statement('UPDATE activity_log SET created_at = COALESCE(created_at, updated_at, now()) WHERE created_at IS NULL');

            DB::statement(<<<'SQL'
                CREATE TABLE activity_log_new (
                    id bigint NOT NULL DEFAULT nextval('activity_log_id_seq'::regclass),
                    log_name varchar(255),
                    description text NOT NULL,
                    subject_type varchar(255),
                    subject_id bigint,
                    event varchar(255),
                    causer_type varchar(255),
                    causer_id bigint,
                    attribute_changes json,
                    properties json,
                    created_at timestamp(0) without time zone,
                    updated_at timestamp(0) without time zone,
                    PRIMARY KEY (created_at, id)
                ) PARTITION BY RANGE (created_at)
                SQL);

            $months = DB::select(<<<'SQL'
                SELECT DISTINCT date_trunc('month', created_at)::date AS month
                FROM activity_log
                ORDER BY month
                SQL);

            $names = [];
            foreach ($months as $row) {
                $month = is_object($row) ? ($row->month ?? null) : null;

                if ($month instanceof DateTimeInterface) {
                    $month = $month->format('Y-m-01');
                }

                if (! is_string($month) || $month === '') {
                    continue;
                }

                $names[] = $this->ensurePartition('activity_log_new', $month);
            }

            $names[] = $this->ensurePartition('activity_log_new', date('Y-m-01'));
            $names[] = $this->ensurePartition('activity_log_new', date('Y-m-01', strtotime('+1 month')));

            DB::statement('INSERT INTO activity_log_new SELECT * FROM activity_log ORDER BY id');

            DB::statement('ALTER TABLE activity_log RENAME TO activity_log_legacy');
            DB::statement('ALTER TABLE activity_log_new RENAME TO activity_log');

            foreach (array_unique($names) as $old) {
                DB::statement("ALTER TABLE {$old} RENAME TO ".str_replace('activity_log_new', 'activity_log', $old));
            }

            DB::statement('ALTER SEQUENCE activity_log_id_seq OWNED BY activity_log.id');
            DB::statement('DROP TABLE activity_log_legacy');

            DB::statement('CREATE INDEX activity_log_log_name_index ON activity_log (log_name)');
            DB::statement('CREATE INDEX activity_log_subject_index ON activity_log (subject_type, subject_id)');
            DB::statement('CREATE INDEX activity_log_causer_index ON activity_log (causer_type, causer_id)');
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            DB::statement('LOCK TABLE activity_log IN ACCESS EXCLUSIVE MODE');

            DB::statement(<<<'SQL'
                CREATE TABLE activity_log_plain (
                    id bigserial PRIMARY KEY,
                    log_name varchar(255),
                    description text NOT NULL,
                    subject_type varchar(255),
                    subject_id bigint,
                    event varchar(255),
                    causer_type varchar(255),
                    causer_id bigint,
                    attribute_changes json,
                    properties json,
                    created_at timestamp(0) without time zone,
                    updated_at timestamp(0) without time zone
                )
                SQL);

            DB::statement('INSERT INTO activity_log_plain SELECT * FROM activity_log ORDER BY id');
            DB::statement('SELECT setval(\'activity_log_id_seq\', (SELECT max(id) FROM activity_log_plain))');
            DB::statement('ALTER TABLE activity_log RENAME TO activity_log_partitioned');
            DB::statement('ALTER TABLE activity_log_plain RENAME TO activity_log');
            DB::statement('DROP TABLE activity_log_partitioned');
            DB::statement('CREATE INDEX activity_log_log_name_index ON activity_log (log_name)');
            DB::statement('CREATE INDEX activity_log_subject_index ON activity_log (subject_type, subject_id)');
            DB::statement('CREATE INDEX activity_log_causer_index ON activity_log (causer_type, causer_id)');
        });
    }

    private function ensurePartition(string $parent, string $monthStart): string
    {
        $start = new DateTimeImmutable($monthStart);
        $end = $start->modify('+1 month');
        $name = $parent.'_'.strtolower($start->format('Y_M'));

        DB::statement(
            "CREATE TABLE IF NOT EXISTS {$name} PARTITION OF {$parent} ".
            "FOR VALUES FROM ('".$start->format('Y-m-d')."') TO ('".$end->format('Y-m-d')."')"
        );

        return $name;
    }
};
