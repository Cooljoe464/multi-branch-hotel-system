<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The transactions table converts to monthly RANGE partitions on
     * business_date during a DBA window (db:partition-transactions),
     * which drops every inbound single-column FK. These two audit links
     * are informational only (folio integrity lives in transactions
     * itself), so they follow the transfer-FK precedent: plain columns
     * with app-level enforcement instead of database constraints.
     */
    public function up(): void
    {
        Schema::table('wifi_sessions', function (Blueprint $table) {
            $table->dropForeign(['folio_transaction_id']);
            $table->index(['reservation_id', 'folio_transaction_id']);
        });

        Schema::table('reservation_hotspots', function (Blueprint $table) {
            $table->dropForeign(['folio_transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::table('reservation_hotspots', function (Blueprint $table) {
            $table->foreign('folio_transaction_id')->references('id')->on('transactions')->nullOnDelete();
        });

        Schema::table('wifi_sessions', function (Blueprint $table) {
            $table->dropIndex(['reservation_id', 'folio_transaction_id']);
            $table->foreign('folio_transaction_id')->references('id')->on('transactions')->nullOnDelete();
        });
    }
};
