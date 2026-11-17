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
        Schema::create('edge_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->uuid('agent_id')->unique();
            $table->char('token_hash', 64)->unique();
            $table->string('protocol_version', 16);
            $table->string('status', 16)->default('active');
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE public.edge_agents ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE public.edge_agents FORCE ROW LEVEL SECURITY');
            DB::statement("CREATE POLICY hms_tenant_isolation ON public.edge_agents FOR ALL USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint OR current_setting('app.is_global_admin', true) = 'true' OR token_hash = NULLIF(current_setting('app.edge_agent_hash', true), '')) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint OR current_setting('app.is_global_admin', true) = 'true')");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('edge_agents');
    }
};
