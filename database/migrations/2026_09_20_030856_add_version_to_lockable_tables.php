<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['reservations', 'rooms', 'folios', 'kot_items', 'pos_charges'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->integer('version')->default(1);
            });
        }
    }

    public function down(): void
    {
        foreach (['reservations', 'rooms', 'folios', 'kot_items', 'pos_charges'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('version');
            });
        }
    }
};
