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
        Schema::create('offline_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('device_id', 128);
            $table->uuid('command_uuid');
            $table->string('command_type', 64);
            $table->json('payload');
            $table->string('status', 16)->default('pending');
            $table->text('reason')->nullable();
            $table->char('override_token_hash', 64)->nullable();
            $table->timestamp('override_expires_at')->nullable();
            $table->timestamp('override_used_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'command_uuid']);
            $table->index(['branch_id', 'status']);
            $table->index(['device_id', 'created_at']);
        });

        $this->enableTenantIsolation('offline_commands');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offline_commands');
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
