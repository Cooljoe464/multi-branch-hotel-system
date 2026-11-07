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
        Schema::create('billing_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('number')->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('tax_minor');
            $table->unsignedBigInteger('total_minor');
            $table->string('status', 16)->default('issued');
            $table->timestamp('issued_at');
            $table->timestamp('due_at');
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference')->nullable()->unique();
            $table->timestamps();

            $table->unique(['subscription_id', 'period_start']);
            $table->index(['status', 'due_at']);
        });

        Schema::create('billing_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_amount_minor');
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();
        });

        foreach (['billing_invoices', 'billing_invoice_lines'] as $tableName) {
            $this->enableTenantIsolation($tableName);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_invoice_lines');
        Schema::dropIfExists('billing_invoices');
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
