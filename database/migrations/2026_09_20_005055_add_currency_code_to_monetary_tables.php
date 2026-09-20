<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'room_types',
            'reservations',
            'folios',
            'transactions',
            'pos_charges',
            'daily_ledgers',
            'rate_plans',
            'rate_overrides',
            'menu_items',
            'inventory_items',
            'maintenance_tickets',
            'kitchen_waste_logs',
            'tablet_orders',
            'folio_disputes',
            'city_ledger_accounts',
            'city_ledger_transactions',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('currency_code', 3)->nullable()->after('id');
            });
        }

        // Backfill from branches for tables with direct branch_id
        $directBranchTables = [
            'room_types',
            'reservations',
            'folios',
            'pos_charges',
            'daily_ledgers',
            'rate_plans',
            'rate_overrides',
            'menu_items',
            'inventory_items',
            'maintenance_tickets',
            'kitchen_waste_logs',
            'tablet_orders',
            'city_ledger_accounts',
        ];

        foreach ($directBranchTables as $table) {
            DB::statement("
                UPDATE {$table}
                SET currency_code = (
                    SELECT b.currency_code
                    FROM branches b
                    WHERE b.id = {$table}.branch_id
                )
                WHERE currency_code IS NULL
            ");
        }

        // Backfill transactions via folios -> branches
        DB::statement('
            UPDATE transactions t
            SET currency_code = (
                SELECT b.currency_code
                FROM folios f
                JOIN branches b ON b.id = f.branch_id
                WHERE f.id = t.folio_id
            )
            WHERE t.currency_code IS NULL
        ');

        // Backfill folio_disputes via folios -> branches
        DB::statement('
            UPDATE folio_disputes fd
            SET currency_code = (
                SELECT b.currency_code
                FROM folios f
                JOIN branches b ON b.id = f.branch_id
                WHERE f.id = fd.folio_id
            )
            WHERE fd.currency_code IS NULL
        ');

        // Backfill city_ledger_transactions via city_ledger_accounts -> branches
        DB::statement('
            UPDATE city_ledger_transactions clt
            SET currency_code = (
                SELECT b.currency_code
                FROM city_ledger_accounts cla
                JOIN branches b ON b.id = cla.branch_id
                WHERE cla.id = clt.city_ledger_account_id
            )
            WHERE clt.currency_code IS NULL
        ');

        // Set default now that backfill is done
        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('currency_code', 3)->nullable(false)->default('NGN')->change();
            });
        }
    }

    public function down(): void
    {
        $tables = [
            'room_types',
            'reservations',
            'folios',
            'transactions',
            'pos_charges',
            'daily_ledgers',
            'rate_plans',
            'rate_overrides',
            'menu_items',
            'inventory_items',
            'maintenance_tickets',
            'kitchen_waste_logs',
            'tablet_orders',
            'folio_disputes',
            'city_ledger_accounts',
            'city_ledger_transactions',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('currency_code');
            });
        }
    }
};
