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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->string('status', 32)->default('trialing');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamp('past_due_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('restricted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        $this->enableTenantIsolation('subscriptions');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
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
