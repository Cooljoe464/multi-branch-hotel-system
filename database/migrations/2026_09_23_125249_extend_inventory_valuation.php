<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->string('valuation_method', 16)->default('weighted_avg');
        });

        Schema::table('recipes', function (Blueprint $table) {
            $table->integer('yield_qty')->default(1);
            $table->integer('wastage_bps')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn('valuation_method');
        });

        Schema::table('recipes', function (Blueprint $table) {
            $table->dropColumn(['yield_qty', 'wastage_bps']);
        });
    }
};
