<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            // Recurring yearly window; end may wrap past New Year.
            $table->unsignedTinyInteger('start_month');
            $table->unsignedTinyInteger('start_day');
            $table->unsignedTinyInteger('end_month');
            $table->unsignedTinyInteger('end_day');
            $table->integer('multiplier_bps')->default(10000);
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'code']);
            $table->index(['branch_id', 'is_active']);
        });

        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->integer('discount_bps')->nullable();
            $table->integer('discount_fixed_minor')->nullable();
            $table->integer('max_uses')->nullable();
            $table->integer('uses_count')->default(0);
            $table->integer('min_nights')->default(1);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'code']);
            $table->index(['branch_id', 'is_active']);
        });

        Schema::create('corporate_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->foreignId('negotiated_plan_id')->nullable()->constrained('rate_plans')->nullOnDelete();
            $table->integer('discount_bps')->default(0);
            $table->string('ledger_account')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'code']);
            $table->index(['branch_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_accounts');
        Schema::dropIfExists('promo_codes');
        Schema::dropIfExists('rate_seasons');
    }
};
