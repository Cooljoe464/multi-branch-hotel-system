<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('rate_plan_id')->nullable()->constrained('rate_plans')->nullOnDelete();
            $table->foreignId('promo_code_id')->nullable()->constrained('promo_codes')->nullOnDelete();
            $table->foreignId('corporate_account_id')->nullable()->constrained('corporate_accounts')->nullOnDelete();
            $table->json('rate_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rate_plan_id');
            $table->dropConstrainedForeignId('promo_code_id');
            $table->dropConstrainedForeignId('corporate_account_id');
            $table->dropColumn('rate_snapshot');
        });
    }
};
