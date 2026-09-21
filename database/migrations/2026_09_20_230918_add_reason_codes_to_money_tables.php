<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('void_reason_code_id')->nullable()->after('transfer_of_transaction_id')
                ->constrained('void_refund_codes')->nullOnDelete();
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->foreignId('refund_reason_code_id')->nullable()->after('webhook_event_id')
                ->constrained('void_refund_codes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refund_reason_code_id');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('void_reason_code_id');
        });
    }
};
