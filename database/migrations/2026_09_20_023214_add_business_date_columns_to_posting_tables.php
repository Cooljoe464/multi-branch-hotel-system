<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->date('current_business_date')->nullable()->after('currency_symbol');
        });

        foreach (['reservations', 'pos_charges', 'payment_transactions'] as $postingTable) {
            Schema::table($postingTable, function (Blueprint $table) {
                $table->date('business_date')->nullable()->after('branch_id');
                $table->index(['branch_id', 'business_date']);
            });
        }

        // transactions belong to a branch through folios, so they carry no branch_id.
        Schema::table('transactions', function (Blueprint $table) {
            $table->date('business_date')->nullable()->after('folio_id');
            $table->index(['folio_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_folio_id_business_date_index');
            $table->dropColumn('business_date');
        });

        foreach (['reservations', 'pos_charges', 'payment_transactions'] as $postingTable) {
            Schema::table($postingTable, function (Blueprint $table) use ($postingTable) {
                $table->dropIndex($postingTable.'_branch_id_business_date_index');
                $table->dropColumn('business_date');
            });
        }

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('current_business_date');
        });
    }
};
