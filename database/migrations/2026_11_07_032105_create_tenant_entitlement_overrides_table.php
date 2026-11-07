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
        Schema::create('tenant_entitlement_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('entitlement_key');
            $table->string('value_type', 16);
            $table->string('value');
            $table->string('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'entitlement_key']);
        });

        $this->enableTenantIsolation('tenant_entitlement_overrides');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_entitlement_overrides');
    }

    private function enableTenantIsolation(string $tableName): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $table = DB::connection()->getQueryGrammar()->wrapTable('public.'.$tableName);
        $tenant = "NULLIF(current_setting('app.tenant_id', true), '')::bigint";
        $globalAdmin = "current_setting('app.is_global_admin', true) = 'true'";
        $expression = "tenant_id = {$tenant} OR {$globalAdmin}";

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS hms_tenant_isolation ON {$table}");
        DB::statement("CREATE POLICY hms_tenant_isolation ON {$table} FOR ALL USING ({$expression}) WITH CHECK ({$expression})");
    }
};
