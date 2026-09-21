<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->bigInteger('opening_float_minor')->default(0);
            $table->bigInteger('expected_cash_minor')->default(0);
            $table->bigInteger('counted_cash_minor')->nullable();
            $table->bigInteger('variance_minor')->nullable();
            $table->string('status', 16)->default('open');
            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'business_date']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_shifts');
    }
};
