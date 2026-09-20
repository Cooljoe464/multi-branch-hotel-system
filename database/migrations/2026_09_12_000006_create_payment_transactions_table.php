<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('folio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('paystack_reference')->unique();
            $table->string('paystack_access_code')->nullable();
            $table->string('type')->default('charge');
            $table->string('status')->default('pending');
            $table->integer('amount');
            $table->string('currency', 3)->default('NGN');
            $table->string('customer_email')->nullable();
            $table->string('authorization_code')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('webhook_payload')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('paystack_reference');
            $table->index(['branch_id', 'status']);
            $table->index('folio_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
