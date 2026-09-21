<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->json('tax_snapshot')->nullable()->after('tax_amount');
            $table->integer('tax_total_minor')->default(0)->after('tax_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['tax_snapshot', 'tax_total_minor']);
        });
    }
};
