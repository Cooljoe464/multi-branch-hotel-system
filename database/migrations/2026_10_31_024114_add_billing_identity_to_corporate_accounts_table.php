<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('corporate_accounts', function (Blueprint $table) {
            $table->string('billing_address', 1000)->nullable();
            $table->text('tax_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('corporate_accounts', function (Blueprint $table) {
            $table->dropColumn(['billing_address', 'tax_id']);
        });
    }
};
