<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('source', 64);
            $table->integer('rate_bps');
            $table->string('base', 16)->default('net_room');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'source']);
            $table->index(['branch_id', 'active']);
        });

        Schema::create('commission_accruals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->string('source', 64);
            $table->bigInteger('base_minor');
            $table->bigInteger('amount_minor');
            $table->string('status', 16)->default('accrued');
            $table->string('idempotency_key', 64)->unique();
            $table->timestamps();

            $table->index(['branch_id', 'source', 'status']);
        });

        Schema::create('commission_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('source', 64);
            $table->bigInteger('amount_minor');
            $table->string('status', 16)->default('pending');
            $table->string('reference', 128)->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'source', 'status']);
        });

        Schema::create('commission_accrual_payout', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_accrual_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commission_payout_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['commission_accrual_id', 'commission_payout_id'], 'accrual_payout_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_accrual_payout');
        Schema::dropIfExists('commission_payouts');
        Schema::dropIfExists('commission_accruals');
        Schema::dropIfExists('commission_rules');
    }
};
