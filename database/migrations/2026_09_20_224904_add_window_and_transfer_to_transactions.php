<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('folio_window_id')->nullable()->after('folio_id')
                ->constrained('folio_windows')->nullOnDelete();
            $table->foreignId('group_master_folio_id')->nullable()->after('folio_window_id')
                ->constrained('folios')->nullOnDelete();
            $table->foreignId('transfer_of_transaction_id')->nullable()->after('group_master_folio_id')
                ->constrained('transactions')->nullOnDelete();
        });

        Schema::table('folios', function (Blueprint $table) {
            $table->boolean('is_master')->default(false)->after('is_settled');
        });
    }

    public function down(): void
    {
        Schema::table('folios', function (Blueprint $table) {
            $table->dropColumn('is_master');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('folio_window_id');
            $table->dropConstrainedForeignId('group_master_folio_id');
            $table->dropConstrainedForeignId('transfer_of_transaction_id');
        });
    }
};
