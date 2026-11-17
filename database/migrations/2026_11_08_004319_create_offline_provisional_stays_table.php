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
        Schema::create('offline_provisional_stays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offline_command_id')->constrained()->cascadeOnDelete();
            $table->string('device_id', 128);
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->string('guest_name', 128);
            $table->string('guest_email', 128)->nullable();
            $table->date('check_in_date');
            $table->date('check_out_date');
            $table->string('status', 16)->default('provisional');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('offline_command_id');
            $table->index(['branch_id', 'room_id', 'check_in_date', 'check_out_date']);
        });

        $this->enableTenantIsolation();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offline_provisional_stays');
    }

    private function enableTenantIsolation(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $table = DB::connection()->getQueryGrammar()->wrapTable('public.offline_provisional_stays');
        $tenant = "NULLIF(current_setting('app.tenant_id', true), '')::bigint";
        $globalAdmin = "current_setting('app.is_global_admin', true) = 'true'";
        $expression = "tenant_id = {$tenant} OR {$globalAdmin}";

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS hms_tenant_isolation ON {$table}");
        DB::statement("CREATE POLICY hms_tenant_isolation ON {$table} FOR ALL USING ({$expression}) WITH CHECK ({$expression})");
    }
};
