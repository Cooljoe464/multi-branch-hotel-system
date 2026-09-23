<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kot_items', function (Blueprint $table) {
            $table->string('course', 16)->default('main');
        });

        DB::table('kot_items')->update(['course' => 'main']);
    }

    public function down(): void
    {
        Schema::table('kot_items', function (Blueprint $table) {
            $table->dropColumn('course');
        });
    }
};
