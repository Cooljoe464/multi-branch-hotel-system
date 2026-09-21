<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_reservations', function (Blueprint $table) {
            $table->foreignId('virtual_card_token_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->string('replay_nonce', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('channel_reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('virtual_card_token_id');
            $table->dropUnique(['replay_nonce']);
            $table->dropColumn('replay_nonce');
        });
    }
};
