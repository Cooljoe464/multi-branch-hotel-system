<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Tenant-scoped activity logs require PostgreSQL.');
        }

        Schema::table('activity_log', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable();
            $table->index('tenant_id', 'activity_log_tenant_id_index');
            $table->foreign('tenant_id', 'activity_log_tenant_id_foreign')
                ->references('id')
                ->on('tenants')
                ->restrictOnDelete();
        });

        foreach ($this->subjectTables() as $modelType => $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'tenant_id')) {
                continue;
            }

            $wrappedTable = DB::connection()->getQueryGrammar()->wrapTable($tableName);
            $escapedModelType = str_replace("'", "''", $modelType);

            foreach (['subject', 'causer'] as $morphColumn) {
                DB::statement(
                    'UPDATE activity_log AS log SET tenant_id = owned_row.tenant_id '
                    .'FROM '.$wrappedTable.' AS owned_row '
                    ."WHERE log.tenant_id IS NULL AND log.{$morphColumn}_type = '{$escapedModelType}' "
                    ."AND log.{$morphColumn}_id = owned_row.id AND owned_row.tenant_id IS NOT NULL"
                );
            }
        }

        DB::statement($this->triggerFunctionSql());
        DB::statement(<<<'SQL'
            CREATE TRIGGER activity_log_tenant_scope_trigger
            BEFORE INSERT OR UPDATE OF subject_type, subject_id, causer_type, causer_id, tenant_id
            ON activity_log
            FOR EACH ROW EXECUTE FUNCTION hospitality_set_activity_tenant()
            SQL);

        foreach (array_merge(['activity_log'], $this->activityLogPartitions()) as $tableName) {
            $this->enableRls($tableName);
        }
    }

    public function down(): void
    {
        foreach (array_merge($this->activityLogPartitions(), ['activity_log']) as $tableName) {
            $table = $this->wrappedTable($tableName);
            DB::statement("DROP POLICY IF EXISTS hms_tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }

        DB::statement('DROP TRIGGER IF EXISTS activity_log_tenant_scope_trigger ON activity_log');
        DB::statement('DROP FUNCTION IF EXISTS hospitality_set_activity_tenant()');

        Schema::table('activity_log', function (Blueprint $table): void {
            $table->dropForeign('activity_log_tenant_id_foreign');
            $table->dropIndex('activity_log_tenant_id_index');
            $table->dropColumn('tenant_id');
        });
    }

    /**
     * @return array<class-string, string>
     */
    private function subjectTables(): array
    {
        return [
            'App\\Models\\Branch' => 'branches',
            'App\\Models\\BusinessDate' => 'business_dates',
            'App\\Models\\DailyLedger' => 'daily_ledgers',
            'App\\Models\\Folio' => 'folios',
            'App\\Models\\Guest' => 'guests',
            'App\\Models\\MaintenanceTicket' => 'maintenance_tickets',
            'App\\Models\\MenuItem' => 'menu_items',
            'App\\Models\\RatePlan' => 'rate_plans',
            'App\\Models\\Reservation' => 'reservations',
            'App\\Models\\Room' => 'rooms',
            'App\\Models\\RoomType' => 'room_types',
            'App\\Models\\Task' => 'tasks',
        ];
    }

    private function triggerFunctionSql(): string
    {
        $grammar = DB::connection()->getQueryGrammar();
        $morphLookups = [];

        foreach ($this->subjectTables() as $modelType => $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'tenant_id')) {
                continue;
            }

            $wrappedTable = $grammar->wrapTable($tableName);
            $escapedModelType = str_replace("'", "''", $modelType);

            foreach (['subject', 'causer'] as $morphColumn) {
                $morphLookups[] = "IF NEW.{$morphColumn}_type = '{$escapedModelType}' THEN "
                    ."SELECT tenant_id INTO referenced_tenant_id FROM {$wrappedTable} "
                    ."WHERE id = NEW.{$morphColumn}_id; "
                    .'IF referenced_tenant_id IS NOT NULL THEN '
                    .'IF resolved_tenant_id IS NOT NULL AND resolved_tenant_id <> referenced_tenant_id THEN '
                    ."RAISE EXCEPTION 'Activity subject and causer belong to different tenants' USING ERRCODE = '23514'; "
                    .'END IF; '
                    .'resolved_tenant_id := referenced_tenant_id; '
                    .'END IF; END IF;';
            }
        }

        return 'CREATE OR REPLACE FUNCTION hospitality_set_activity_tenant() '
            .'RETURNS trigger LANGUAGE plpgsql AS $activity_tenant$ '
            .'DECLARE request_tenant_id bigint; referenced_tenant_id bigint; resolved_tenant_id bigint; BEGIN '
            ."request_tenant_id := NULLIF(current_setting('app.tenant_id', true), '')::bigint; "
            .implode(' ', $morphLookups).' '
            .'IF request_tenant_id IS NOT NULL AND resolved_tenant_id IS NOT NULL '
            .'AND request_tenant_id <> resolved_tenant_id THEN '
            ."RAISE EXCEPTION 'Activity subject belongs to another tenant' USING ERRCODE = '23514'; "
            .'END IF; '
            .'IF NEW.tenant_id IS NOT NULL AND resolved_tenant_id IS NOT NULL '
            .'AND NEW.tenant_id <> resolved_tenant_id THEN '
            ."RAISE EXCEPTION 'Activity record tenant does not match its subject' USING ERRCODE = '23514'; "
            .'END IF; '
            .'NEW.tenant_id := COALESCE(request_tenant_id, NEW.tenant_id, resolved_tenant_id); '
            .'RETURN NEW; END; $activity_tenant$';
    }

    /**
     * @return list<string>
     */
    private function activityLogPartitions(): array
    {
        return array_map(
            static fn (object $partition): string => $partition->partition_name,
            DB::select(<<<'SQL'
                SELECT child.relname AS partition_name
                FROM pg_inherits
                JOIN pg_class AS parent ON parent.oid = inhparent
                JOIN pg_class AS child ON child.oid = inhrelid
                JOIN pg_namespace AS parent_schema ON parent_schema.oid = parent.relnamespace
                WHERE parent_schema.nspname = 'public'
                    AND parent.relname = 'activity_log'
                ORDER BY child.relname
                SQL),
        );
    }

    private function enableRls(string $tableName): void
    {
        $table = $this->wrappedTable($tableName);
        $tenantExpression = "tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint";
        $globalExpression = "tenant_id IS NULL AND current_setting('app.is_global_admin', true) = 'true'";
        $policyExpression = "{$tenantExpression} OR ({$globalExpression})";

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS hms_tenant_isolation ON {$table}");
        DB::statement(
            "CREATE POLICY hms_tenant_isolation ON {$table} FOR ALL "
            ."USING ({$policyExpression}) WITH CHECK ({$policyExpression})"
        );
    }

    private function wrappedTable(string $tableName): string
    {
        return DB::connection()->getQueryGrammar()->wrapTable('public.'.$tableName);
    }
};
