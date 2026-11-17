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
        Schema::create('public_payment_intents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('api_key_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hold_id')->constrained('inventory_holds')->cascadeOnDelete();
            $table->foreignId('quote_id')->constrained('public_api_quotes')->restrictOnDelete();
            $table->foreignId('payment_connection_id')->constrained('payment_connections')->restrictOnDelete();
            $table->foreignId('folio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('guest_name', 128);
            $table->string('guest_email', 128);
            $table->string('guest_phone', 32)->nullable();
            $table->unsignedSmallInteger('adults')->default(1);
            $table->unsignedSmallInteger('children')->default(0);
            $table->string('status', 16)->default('initializing');
            $table->string('gateway_reference', 96)->nullable()->unique();
            $table->char('access_token_hash', 64);
            $table->text('checkout_url')->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency_code', 3);
            $table->timestampTz('expires_at');
            $table->timestamps();

            $table->unique(['api_key_id', 'hold_id']);
            $table->index(['branch_id', 'status', 'expires_at']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $table = DB::connection()->getQueryGrammar()->wrapTable('public.public_payment_intents');
            $tenant = "NULLIF(current_setting('app.tenant_id', true), '')::bigint";
            $globalAdmin = "current_setting('app.is_global_admin', true) = 'true'";
            $expression = "tenant_id = {$tenant} OR {$globalAdmin}";

            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS hms_tenant_isolation ON {$table}");
            DB::statement("CREATE POLICY hms_tenant_isolation ON {$table} FOR ALL USING ({$expression}) WITH CHECK ({$expression})");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('public_payment_intents');
    }
};
