<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->string('driver', 32)->default('paystack')->after('type');
            $table->string('kind', 16)->default('sale')->after('driver');
            $table->foreignId('parent_id')->nullable()->after('kind')
                ->constrained('payment_transactions')->nullOnDelete();
            $table->string('webhook_event_id', 128)->nullable()->after('parent_id');
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->unique(['driver', 'webhook_event_id']);
            $table->index(['parent_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropUnique(['driver', 'webhook_event_id']);
            $table->dropIndex(['parent_id', 'kind']);
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['driver', 'kind', 'webhook_event_id']);
        });
    }
};
