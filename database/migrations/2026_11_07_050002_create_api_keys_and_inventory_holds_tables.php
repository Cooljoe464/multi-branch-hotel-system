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
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 16);
            $table->string('environment', 8);
            $table->string('prefix', 16);
            $table->char('key_hash', 64)->unique();
            $table->char('previous_key_hash', 64)->nullable()->index();
            $table->timestamp('previous_valid_until')->nullable();
            $table->json('allowed_origins')->nullable();
            $table->json('ip_allowlist')->nullable();
            $table->json('scopes');
            $table->unsignedInteger('hold_limit_per_key')->default(100);
            $table->unsignedInteger('hold_limit_per_ip')->default(20);
            $table->unsignedInteger('hold_limit_per_session')->default(5);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'type']);
        });

        Schema::create('inventory_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained('api_keys')->nullOnDelete();
            $table->string('source', 32)->default('public_api');
            $table->date('check_in');
            $table->date('check_out');
            $table->string('status', 16)->default('active');
            $table->string('session_id', 128);
            $table->string('client_ip', 45);
            $table->timestamp('expires_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index(['api_key_id', 'status']);
            $table->index(['client_ip', 'status']);
            $table->index(['session_id', 'status']);
        });

        $this->enableKeyTenantIsolation();
        $this->enableHoldTenantIsolation();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_holds');
        Schema::dropIfExists('api_keys');
    }

    /**
     * A key must be found by its hash before any tenant is known, so the
     * policy also admits a row whose hash matches the one hash the caller has
     * set for this transaction. Nothing else crosses a tenant boundary.
     */
    private function enableKeyTenantIsolation(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $table = DB::connection()->getQueryGrammar()->wrapTable('public.api_keys');
        $tenant = "NULLIF(current_setting('app.tenant_id', true), '')::bigint";
        $globalAdmin = "current_setting('app.is_global_admin', true) = 'true'";
        $lookup = "NULLIF(current_setting('app.api_key_hash', true), '')";
        $read = "tenant_id = {$tenant} OR {$globalAdmin} OR key_hash = {$lookup} OR previous_key_hash = {$lookup}";
        $write = "tenant_id = {$tenant} OR {$globalAdmin}";

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS hms_tenant_isolation ON {$table}");
        DB::statement("CREATE POLICY hms_tenant_isolation ON {$table} FOR ALL USING ({$read}) WITH CHECK ({$write})");
    }

    private function enableHoldTenantIsolation(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $table = DB::connection()->getQueryGrammar()->wrapTable('public.inventory_holds');
        $tenant = "NULLIF(current_setting('app.tenant_id', true), '')::bigint";
        $globalAdmin = "current_setting('app.is_global_admin', true) = 'true'";
        $expression = "tenant_id = {$tenant} OR {$globalAdmin}";

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS hms_tenant_isolation ON {$table}");
        DB::statement("CREATE POLICY hms_tenant_isolation ON {$table} FOR ALL USING ({$expression}) WITH CHECK ({$expression})");
    }
};
