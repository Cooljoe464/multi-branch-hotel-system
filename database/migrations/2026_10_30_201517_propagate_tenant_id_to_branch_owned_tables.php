<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hospitality_set_tenant_from_branch()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                property_tenant_id bigint;
            BEGIN
                IF NEW.branch_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT branches.tenant_id
                INTO property_tenant_id
                FROM branches
                WHERE branches.id = NEW.branch_id;

                IF property_tenant_id IS NULL THEN
                    RAISE EXCEPTION 'Cannot assign tenant ownership for branch %', NEW.branch_id
                        USING ERRCODE = '23503';
                END IF;

                IF NEW.tenant_id IS NOT NULL AND NEW.tenant_id <> property_tenant_id THEN
                    RAISE EXCEPTION 'Tenant % does not own branch %', NEW.tenant_id, NEW.branch_id
                        USING ERRCODE = '23514';
                END IF;

                NEW.tenant_id := property_tenant_id;

                RETURN NEW;
            END;
            $$
            SQL);

        foreach ($this->branchOwnedTables() as $tableName) {
            if (! Schema::hasColumn($tableName, 'tenant_id')) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                    $table->unsignedBigInteger('tenant_id')->nullable();
                    $table->index('tenant_id', $this->constraintName($tableName, 'tenant_idx'));
                    $table->foreign('tenant_id', $this->constraintName($tableName, 'tenant_fk'))
                        ->references('id')
                        ->on('tenants')
                        ->restrictOnDelete();
                });
            }

            $grammar = DB::connection()->getQueryGrammar();
            $wrappedTable = $grammar->wrapTable($tableName);
            $wrappedTenantId = $grammar->wrap('tenant_id');
            $wrappedBranchId = $grammar->wrap('branch_id');
            $wrappedBranchTable = $grammar->wrapTable('branches');

            DB::statement("UPDATE {$wrappedTable} AS tenant_owned_row SET {$wrappedTenantId} = property.tenant_id FROM {$wrappedBranchTable} AS property WHERE tenant_owned_row.{$wrappedBranchId} = property.id AND tenant_owned_row.{$wrappedTenantId} IS NULL");
            DB::statement("CREATE TRIGGER tenant_from_branch_trigger BEFORE INSERT OR UPDATE OF branch_id, tenant_id ON {$wrappedTable} FOR EACH ROW EXECUTE FUNCTION hospitality_set_tenant_from_branch()");

            if (DB::table($tableName)->whereNotNull('branch_id')->whereNull('tenant_id')->exists()) {
                throw new RuntimeException("Tenant ownership backfill left rows without an owner in [{$tableName}].");
            }
        }

        foreach ($this->tenantOwnedDescendants() as $tableName => $foreignKeys) {
            if (! Schema::hasColumn($tableName, 'tenant_id')) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                    $table->foreignId('tenant_id')->nullable();
                    $table->index('tenant_id', $this->constraintName($tableName, 'tenant_idx'));
                    $table->foreign('tenant_id', $this->constraintName($tableName, 'tenant_fk'))
                        ->references('id')
                        ->on('tenants')
                        ->restrictOnDelete();
                });
            }

            foreach ($foreignKeys as $foreignKey) {
                $this->backfillTenantFromParent($tableName, $foreignKey);
            }

            $this->createParentTenantTrigger($tableName, $foreignKeys);
            $this->assertParentTenantConsistency($tableName, $foreignKeys);
        }
    }

    public function down(): void
    {
        foreach ($this->tenantOwnedDescendants() as $tableName => $foreignKeys) {
            $functionName = $this->parentTriggerFunctionName($tableName);
            $wrappedTable = DB::connection()->getQueryGrammar()->wrapTable($tableName);

            DB::statement("DROP TRIGGER IF EXISTS tenant_from_parent_trigger ON {$wrappedTable}");
            DB::statement('DROP FUNCTION IF EXISTS '.$this->wrappedFunctionName($functionName).'()');

            if (Schema::hasColumn($tableName, 'tenant_id')) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                    $table->dropForeign($this->constraintName($tableName, 'tenant_fk'));
                    $table->dropIndex($this->constraintName($tableName, 'tenant_idx'));
                    $table->dropColumn('tenant_id');
                });
            }
        }

        foreach ($this->branchOwnedTables() as $tableName) {
            $wrappedTable = DB::connection()->getQueryGrammar()->wrapTable($tableName);

            DB::statement("DROP TRIGGER IF EXISTS tenant_from_branch_trigger ON {$wrappedTable}");

            if (Schema::hasColumn($tableName, 'tenant_id')) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                    $table->dropForeign($this->constraintName($tableName, 'tenant_fk'));
                    $table->dropIndex($this->constraintName($tableName, 'tenant_idx'));
                    $table->dropColumn('tenant_id');
                });
            }
        }

        DB::statement('DROP FUNCTION IF EXISTS hospitality_set_tenant_from_branch()');
    }

    /**
     * @return list<string>
     */
    private function branchOwnedTables(): array
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('column_name', 'branch_id')
            ->whereNotIn('table_name', ['branches', 'users'])
            ->orderBy('table_name')
            ->distinct()
            ->pluck('table_name')
            ->all();
    }

    /**
     * @return array<string, list<array{constraint_name: string, parent_table: string, columns: list<array{child: string, parent: string}>}>>
     */
    private function tenantOwnedDescendants(): array
    {
        $foreignKeys = $this->foreignKeyRelations();
        $tenantOwnedTables = array_fill_keys(array_merge($this->branchOwnedTables(), ['branches']), true);
        $descendantTableNames = [];

        do {
            $newTableFound = false;

            foreach ($foreignKeys as $foreignKey) {
                $childTable = $foreignKey['child_table'];
                $parentTable = $foreignKey['parent_table'];

                if (! isset($tenantOwnedTables[$parentTable]) || isset($tenantOwnedTables[$childTable])) {
                    continue;
                }

                if (in_array($childTable, ['tenants', 'users'], true)) {
                    continue;
                }

                $tenantOwnedTables[$childTable] = true;
                $descendantTableNames[$childTable] = true;
                $newTableFound = true;
            }
        } while ($newTableFound);

        $descendants = [];

        foreach ($foreignKeys as $foreignKey) {
            $childTable = $foreignKey['child_table'];
            $parentTable = $foreignKey['parent_table'];

            if (! isset($descendantTableNames[$childTable]) || ! isset($tenantOwnedTables[$parentTable])) {
                continue;
            }

            $descendants[$childTable][$foreignKey['constraint_name']] = [
                'constraint_name' => $foreignKey['constraint_name'],
                'parent_table' => $parentTable,
                'columns' => $foreignKey['columns'],
            ];
        }

        $orderedDescendants = [];
        $pendingTableNames = array_fill_keys(array_keys($descendants), true);
        $availableParentTables = array_fill_keys(array_merge($this->branchOwnedTables(), ['branches']), true);

        while ($pendingTableNames !== []) {
            $tableAdded = false;

            foreach (array_keys($pendingTableNames) as $tableName) {
                $parentTableNames = array_unique(array_map(
                    static fn (array $foreignKey): string => $foreignKey['parent_table'],
                    array_values($descendants[$tableName]),
                ));
                $hasUnavailableParent = false;

                foreach ($parentTableNames as $parentTableName) {
                    if ($parentTableName !== $tableName && ! isset($availableParentTables[$parentTableName])) {
                        $hasUnavailableParent = true;
                        break;
                    }
                }

                if ($hasUnavailableParent) {
                    continue;
                }

                $orderedDescendants[$tableName] = array_values($descendants[$tableName]);
                $availableParentTables[$tableName] = true;
                unset($pendingTableNames[$tableName]);
                $tableAdded = true;
            }

            if (! $tableAdded) {
                throw new RuntimeException('Tenant ownership foreign-key dependencies contain a cycle.');
            }
        }

        return $orderedDescendants;
    }

    /**
     * @return list<array{child_table: string, constraint_name: string, parent_table: string, columns: list<array{child: string, parent: string}>}>
     */
    private function foreignKeyRelations(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT
                child_class.relname AS child_table,
                foreign_key.conname AS constraint_name,
                parent_class.relname AS parent_table,
                child_column.attname AS child_column,
                parent_column.attname AS parent_column,
                key_pair.key_position
            FROM pg_constraint AS foreign_key
            JOIN pg_class AS child_class ON child_class.oid = foreign_key.conrelid
            JOIN pg_namespace AS child_schema ON child_schema.oid = child_class.relnamespace
            JOIN pg_class AS parent_class ON parent_class.oid = foreign_key.confrelid
            JOIN pg_namespace AS parent_schema ON parent_schema.oid = parent_class.relnamespace
            CROSS JOIN LATERAL unnest(foreign_key.conkey, foreign_key.confkey)
                WITH ORDINALITY AS key_pair(child_attribute_number, parent_attribute_number, key_position)
            JOIN pg_attribute AS child_column
                ON child_column.attrelid = child_class.oid
                AND child_column.attnum = key_pair.child_attribute_number
            JOIN pg_attribute AS parent_column
                ON parent_column.attrelid = parent_class.oid
                AND parent_column.attnum = key_pair.parent_attribute_number
            WHERE foreign_key.contype = 'f'
                AND child_schema.nspname = 'public'
                AND parent_schema.nspname = 'public'
                AND child_class.relkind IN ('r', 'p')
                AND parent_class.relkind IN ('r', 'p')
                AND NOT child_class.relispartition
            ORDER BY child_class.relname, foreign_key.conname, key_pair.key_position
            SQL);

        $relations = [];

        foreach ($rows as $row) {
            $key = $row->child_table.'|'.$row->constraint_name;

            if (! isset($relations[$key])) {
                $relations[$key] = [
                    'child_table' => $row->child_table,
                    'constraint_name' => $row->constraint_name,
                    'parent_table' => $row->parent_table,
                    'columns' => [],
                ];
            }

            $relations[$key]['columns'][] = [
                'child' => $row->child_column,
                'parent' => $row->parent_column,
            ];
        }

        return array_values($relations);
    }

    /**
     * @param  array{constraint_name: string, parent_table: string, columns: list<array{child: string, parent: string}>}  $foreignKey
     */
    private function backfillTenantFromParent(string $tableName, array $foreignKey): void
    {
        $grammar = DB::connection()->getQueryGrammar();
        $wrappedChildTable = $grammar->wrapTable($tableName);
        $wrappedParentTable = $grammar->wrapTable($foreignKey['parent_table']);
        $joinConditions = [];

        foreach ($foreignKey['columns'] as $column) {
            $joinConditions[] = 'tenant_owned_row.'.$grammar->wrap($column['child'])
                .' = tenant_parent.'.$grammar->wrap($column['parent']);
        }

        DB::statement(
            'UPDATE '.$wrappedChildTable.' AS tenant_owned_row '
            .'SET '.$grammar->wrap('tenant_id').' = tenant_parent.'.$grammar->wrap('tenant_id').' '
            .'FROM '.$wrappedParentTable.' AS tenant_parent '
            .'WHERE '.implode(' AND ', $joinConditions)
            .' AND tenant_parent.'.$grammar->wrap('tenant_id').' IS NOT NULL'
            .' AND tenant_owned_row.'.$grammar->wrap('tenant_id').' IS NULL'
        );
    }

    /**
     * @param  list<array{constraint_name: string, parent_table: string, columns: list<array{child: string, parent: string}>}>  $foreignKeys
     */
    private function createParentTenantTrigger(string $tableName, array $foreignKeys): void
    {
        $grammar = DB::connection()->getQueryGrammar();
        $functionName = $this->parentTriggerFunctionName($tableName);
        $wrappedFunctionName = $this->wrappedFunctionName($functionName);
        $triggerBody = [];
        $triggerColumns = ['tenant_id'];

        foreach ($foreignKeys as $foreignKey) {
            $wrappedParentTable = $grammar->wrapTable($foreignKey['parent_table']);
            $joinConditions = [];
            $notNullConditions = [];

            foreach ($foreignKey['columns'] as $column) {
                $wrappedChildColumn = $grammar->wrap($column['child']);
                $wrappedParentColumn = $grammar->wrap($column['parent']);
                $joinConditions[] = 'tenant_parent.'.$wrappedParentColumn.' = NEW.'.$wrappedChildColumn;
                $notNullConditions[] = 'NEW.'.$wrappedChildColumn.' IS NOT NULL';
                $triggerColumns[] = $column['child'];
            }

            $triggerBody[] = 'IF '.implode(' AND ', $notNullConditions).' THEN '
                .'SELECT tenant_parent.'.$grammar->wrap('tenant_id').' INTO referenced_tenant_id '
                .'FROM '.$wrappedParentTable.' AS tenant_parent '
                .'WHERE '.implode(' AND ', $joinConditions).'; '
                .'IF referenced_tenant_id IS NOT NULL THEN '
                .'IF NEW.'.$grammar->wrap('tenant_id').' IS NOT NULL '
                .'AND NEW.'.$grammar->wrap('tenant_id').' <> referenced_tenant_id THEN '
                ."RAISE EXCEPTION 'Tenant % does not own the referenced tenant %', "
                .'NEW.'.$grammar->wrap('tenant_id').', referenced_tenant_id '
                ."USING ERRCODE = '23514'; "
                .'END IF; '
                .'NEW.'.$grammar->wrap('tenant_id').' := referenced_tenant_id; '
                .'END IF; '
                .'END IF;';
        }

        $contextTenant = "NULLIF(current_setting('app.tenant_id', true), '')::bigint";
        $triggerBody[] = 'IF NEW.'.$grammar->wrap('tenant_id').' IS NULL THEN '
            .'NEW.'.$grammar->wrap('tenant_id').' := '.$contextTenant.'; '
            .'END IF;';

        DB::statement(
            'CREATE OR REPLACE FUNCTION '.$wrappedFunctionName.'() RETURNS trigger '
            .'LANGUAGE plpgsql AS $tenant_parent$ '
            .'DECLARE referenced_tenant_id bigint; BEGIN '
            .implode(' ', $triggerBody).' RETURN NEW; END; $tenant_parent$'
        );

        $wrappedTable = $grammar->wrapTable($tableName);
        $wrappedTriggerColumns = implode(', ', array_map(
            static fn (string $column): string => $grammar->wrap($column),
            array_values(array_unique($triggerColumns)),
        ));

        DB::statement(
            "CREATE TRIGGER tenant_from_parent_trigger BEFORE INSERT OR UPDATE OF {$wrappedTriggerColumns} "
            ."ON {$wrappedTable} FOR EACH ROW EXECUTE FUNCTION {$wrappedFunctionName}()"
        );
    }

    /**
     * @param  list<array{constraint_name: string, parent_table: string, columns: list<array{child: string, parent: string}>}>  $foreignKeys
     */
    private function assertParentTenantConsistency(string $tableName, array $foreignKeys): void
    {
        $grammar = DB::connection()->getQueryGrammar();
        $wrappedChildTable = $grammar->wrapTable($tableName);

        foreach ($foreignKeys as $foreignKey) {
            $wrappedParentTable = $grammar->wrapTable($foreignKey['parent_table']);
            $joinConditions = [];

            foreach ($foreignKey['columns'] as $column) {
                $joinConditions[] = 'tenant_owned_row.'.$grammar->wrap($column['child'])
                    .' = tenant_parent.'.$grammar->wrap($column['parent']);
            }

            $mismatchExists = DB::selectOne(
                'SELECT EXISTS (SELECT 1 FROM '.$wrappedChildTable.' AS tenant_owned_row '
                .'JOIN '.$wrappedParentTable.' AS tenant_parent ON '.implode(' AND ', $joinConditions).' '
                .'WHERE tenant_parent.'.$grammar->wrap('tenant_id').' IS NOT NULL '
                .'AND tenant_owned_row.'.$grammar->wrap('tenant_id').' IS DISTINCT FROM tenant_parent.'.$grammar->wrap('tenant_id').') AS mismatch',
            );

            if (in_array($mismatchExists->mismatch ?? false, [true, 1, '1', 't', 'true'], true)) {
                throw new RuntimeException("Tenant ownership does not match a referenced tenant row in [{$tableName}].");
            }
        }
    }

    private function parentTriggerFunctionName(string $tableName): string
    {
        return 'hospitality_tenant_parent_'.substr(hash('sha256', $tableName), 0, 12);
    }

    private function wrappedFunctionName(string $functionName): string
    {
        return DB::connection()->getQueryGrammar()->wrapTable('public.'.$functionName);
    }

    private function constraintName(string $tableName, string $suffix): string
    {
        return substr($tableName, 0, 28).'_'.substr(hash('sha256', $tableName), 0, 8).'_'.$suffix;
    }
};
