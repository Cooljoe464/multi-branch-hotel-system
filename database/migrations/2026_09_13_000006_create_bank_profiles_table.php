<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('bank_name');
            $table->string('account_number');
            $table->string('account_name');
            $table->string('swift_code')->nullable();
            $table->string('sort_code')->nullable();
            $table->string('currency_code')->default('USD');
            $table->boolean('is_default')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'is_default']);
        });

        Schema::create('group_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->decimal('total_room_revenue', 15, 2)->default(0);
            $table->decimal('total_pos_revenue', 15, 2)->default(0);
            $table->decimal('total_tax', 15, 2)->default(0);
            $table->decimal('total_payments', 15, 2)->default(0);
            $table->decimal('net_revenue', 15, 2)->default(0);
            $table->string('currency_code')->default('USD');
            $table->decimal('exchange_rate_to_group', 12, 6)->default(1.000000);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_ledgers');
        Schema::dropIfExists('bank_profiles');
    }
};
