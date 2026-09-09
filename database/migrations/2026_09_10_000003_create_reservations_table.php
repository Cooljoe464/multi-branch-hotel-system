<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->string('confirmation_number')->unique();
            $table->string('status')->default('pending');
            $table->string('source')->default('direct');
            $table->string('guest_name');
            $table->string('guest_email')->nullable();
            $table->string('guest_phone')->nullable();
            $table->text('guest_notes')->nullable();
            $table->integer('adults')->default(1);
            $table->integer('children')->default(0);
            $table->date('check_in_date');
            $table->date('check_out_date');
            $table->timestamp('actual_check_in_at')->nullable();
            $table->timestamp('actual_check_out_at')->nullable();
            $table->integer('room_rate');
            $table->integer('total_amount')->default(0);
            $table->integer('amount_paid')->default(0);
            $table->string('payment_status')->default('pending');
            $table->boolean('is_group_booking')->default(false);
            $table->string('group_id')->nullable();
            $table->json('special_requests')->nullable();
            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
            $table->index('check_in_date');
            $table->index('check_out_date');
            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'check_in_date']);
            $table->index('confirmation_number');
            $table->index('group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
