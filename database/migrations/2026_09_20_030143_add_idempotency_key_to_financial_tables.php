<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['transactions', 'reservations', 'payment_transactions', 'folios'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('idempotency_key', 64)->nullable();
                $table->index('idempotency_key');
            });
        }
    }

    public function down(): void
    {
        foreach (['transactions', 'reservations', 'payment_transactions', 'folios'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropIndex($tableName.'_idempotency_key_index');
                $table->dropColumn('idempotency_key');
            });
        }
    }
};
