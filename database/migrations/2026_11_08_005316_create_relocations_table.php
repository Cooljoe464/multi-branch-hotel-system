<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('relocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('destination_branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('destination_room_id')->constrained('rooms')->cascadeOnDelete();
            $table->string('status', 16)->default('pending');
            $table->string('reason', 500);
            $table->unsignedBigInteger('compensation_minor')->default(0);
            $table->string('currency_code', 3);
            $table->string('idempotency_key', 128);
            $table->foreignId('compensation_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['reservation_id', 'idempotency_key']);
            $table->index(['source_branch_id', 'status']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $table = DB::connection()->getQueryGrammar()->wrapTable('public.relocations');
            $tenant = "NULLIF(current_setting('app.tenant_id', true), '')::bigint";
            $globalAdmin = "current_setting('app.is_global_admin', true) = 'true'";
            $expression = "tenant_id = {$tenant} OR {$globalAdmin}";
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS hms_tenant_isolation ON {$table}");
            DB::statement("CREATE POLICY hms_tenant_isolation ON {$table} FOR ALL USING ({$expression}) WITH CHECK ({$expression})");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('relocations');
    }
};
