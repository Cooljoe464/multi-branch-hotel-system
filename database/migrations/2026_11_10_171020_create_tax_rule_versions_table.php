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
        Schema::create('tax_rule_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('rule_key', 96);
            $table->json('value');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->json('evidence_snapshot')->nullable();
            $table->string('approval_reference', 255)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'rule_key', 'effective_from']);
            $table->index(['branch_id', 'rule_key', 'effective_from']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE public.tax_rule_versions ADD CONSTRAINT tax_rule_versions_effective_dates_check CHECK (effective_until IS NULL OR effective_until >= effective_from)');
            DB::statement('CREATE UNIQUE INDEX tax_rule_versions_tenant_scope_unique ON public.tax_rule_versions (tenant_id, COALESCE(branch_id, 0), rule_key, effective_from)');

            $table = DB::connection()->getQueryGrammar()->wrapTable('public.tax_rule_versions');
            $tenant = "NULLIF(current_setting('app.tenant_id', true), '')::bigint";
            $globalAdmin = "current_setting('app.is_global_admin', true) = 'true'";
            $expression = "tenant_id = {$tenant} OR {$globalAdmin}";

            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY hms_tenant_isolation ON {$table} FOR ALL USING ({$expression}) WITH CHECK ({$expression})");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_rule_versions');
    }
};
