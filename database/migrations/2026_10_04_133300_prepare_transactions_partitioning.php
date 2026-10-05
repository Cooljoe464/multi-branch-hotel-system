<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 of the transactions partitioning cutover (safe to run in
 * a normal deploy): NOT NULL business_date on transactions, plus a
 * nullable business_date mirror on the three child tables that will
 * carry composite FKs after the cutover. Writers populate the
 * mirror from the parent at link time; NULL means "not yet linked"
 * and never constrains anything.
 *
 * The actual partition swap lives in `db:partition-transactions`
 * (DBA maintenance window) — see docs/DR-RUNBOOK.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('UPDATE transactions SET business_date = COALESCE(business_date, created_at::date, CURRENT_DATE) WHERE business_date IS NULL');

        Schema::table('transactions', function (Blueprint $table) {
            $table->date('business_date')->nullable(false)->change();
        });

        foreach (['pos_charges', 'folio_disputes', 'transaction_splits'] as $table) {
            if (! Schema::hasColumn($table, 'business_date')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->date('business_date')->nullable()->after('transaction_id');
                });
            }
        }

        DB::statement('UPDATE pos_charges pc SET business_date = t.business_date FROM transactions t WHERE t.id = pc.transaction_id AND pc.business_date IS NULL');
        DB::statement('UPDATE folio_disputes fd SET business_date = t.business_date FROM transactions t WHERE t.id = fd.transaction_id AND fd.business_date IS NULL');
        DB::statement('UPDATE transaction_splits ts SET business_date = t.business_date FROM transactions t WHERE t.id = ts.transaction_id AND ts.business_date IS NULL');
    }

    public function down(): void
    {
        foreach (['pos_charges', 'folio_disputes', 'transaction_splits'] as $table) {
            if (! Schema::hasColumn($table, 'business_date')) {
                continue;
            }

            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('business_date');
            });
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->date('business_date')->nullable()->change();
        });
    }
};
