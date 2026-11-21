<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_drops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cashier_shift_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount_minor');
            $table->string('envelope_number', 64);
            $table->foreignId('witness_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dropped_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['cashier_shift_id', 'dropped_at']);
        });

        Schema::create('operational_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('department', 64); // front_desk, housekeeping, food_beverage, security, maintenance
            $table->string('shift_type', 32); // morning, evening, night
            $table->string('title');
            $table->json('items'); // array of [{id, task, done, checked_at, note}]
            $table->string('status', 32)->default('pending'); // pending, completed, reviewed
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'department', 'status']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_checklists');
        Schema::dropIfExists('cash_drops');
    }
};
