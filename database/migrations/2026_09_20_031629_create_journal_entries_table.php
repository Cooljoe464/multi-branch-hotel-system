<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->string('event', 64);
            $table->string('debit_account', 32);
            $table->string('credit_account', 32);
            $table->bigInteger('amount_minor');
            $table->char('currency_code', 3)->default('NGN');
            // 1.0 scaled by 1e6. Integer by convention: never a float column.
            $table->bigInteger('fx_rate_to_branch_minor')->default(1000000);
            $table->nullableMorphs('source');
            $table->string('idempotency_scope', 64)->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['idempotency_scope', 'idempotency_key']);
            $table->index(['branch_id', 'business_date']);
            $table->index(['event', 'business_date']);
        });

        // Append-only enforcement at the database level (PostgreSQL).
        // Corrections are compensating reversal entries, never UPDATE/DELETE.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION forbid_journal_mutation()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'journal_entries is append-only (attempted % on id %)', TG_OP, OLD.id;
                END;
                $$ LANGUAGE plpgsql
                SQL);

            DB::statement(<<<'SQL'
                CREATE TRIGGER journal_entries_no_update_no_delete
                BEFORE UPDATE OR DELETE ON journal_entries
                FOR EACH ROW EXECUTE FUNCTION forbid_journal_mutation()
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS journal_entries_no_update_no_delete ON journal_entries');
            DB::statement('DROP FUNCTION IF EXISTS forbid_journal_mutation()');
        }

        Schema::dropIfExists('journal_entries');
    }
};
