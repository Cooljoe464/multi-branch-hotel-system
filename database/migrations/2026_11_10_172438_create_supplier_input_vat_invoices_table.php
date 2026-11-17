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
        Schema::create('supplier_input_vat_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('supplier_invoice_number', 128);
            $table->string('supplier_tax_id', 32)->nullable();
            $table->date('invoice_date');
            $table->date('supply_date');
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('net_amount_minor');
            $table->unsignedBigInteger('vat_amount_minor');
            $table->unsignedBigInteger('taxable_use_minor');
            $table->unsignedBigInteger('exempt_use_minor');
            $table->boolean('validated_einvoice')->default(false);
            $table->string('einvoice_reference', 128)->nullable();
            $table->json('evidence_snapshot')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at');
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'supplier_id', 'supplier_invoice_number'], 'supplier_input_vat_invoice_ref_unique');
            $table->index(['tenant_id', 'supply_date', 'voided_at']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE public.supplier_input_vat_invoices ADD CONSTRAINT supplier_input_vat_invoice_amounts_check CHECK (net_amount_minor >= 0 AND vat_amount_minor >= 0 AND taxable_use_minor + exempt_use_minor > 0)');
            $table = DB::connection()->getQueryGrammar()->wrapTable('public.supplier_input_vat_invoices');
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
        Schema::dropIfExists('supplier_input_vat_invoices');
    }
};
