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
        Schema::create('payment_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('provider', 24);
            $table->string('mode', 8)->default('live');
            $table->string('name', 128);
            $table->text('secret_key');
            $table->text('webhook_secret')->nullable();
            $table->string('merchant_reference', 128)->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'branch_id', 'provider', 'status']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX payment_connections_default_scope_unique ON public.payment_connections (tenant_id, COALESCE(branch_id, 0)) WHERE is_default');

            $table = DB::connection()->getQueryGrammar()->wrapTable('public.payment_connections');
            $tenant = "NULLIF(current_setting('app.tenant_id', true), '')::bigint";
            $globalAdmin = "current_setting('app.is_global_admin', true) = 'true'";
            $expression = "tenant_id = {$tenant} OR {$globalAdmin}";

            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS hms_tenant_isolation ON {$table}");
            DB::statement("CREATE POLICY hms_tenant_isolation ON {$table} FOR ALL USING ({$expression}) WITH CHECK ({$expression})");
            $connectionId = "NULLIF(current_setting('app.payment_connection_id', true), '')::bigint";
            DB::statement("CREATE POLICY hms_payment_webhook_lookup ON {$table} FOR SELECT USING (id = {$connectionId})");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_connections');
    }
};
