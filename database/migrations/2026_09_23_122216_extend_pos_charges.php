<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_charges', function (Blueprint $table) {
            $table->foreignId('dining_table_id')->nullable()->constrained('dining_tables')->nullOnDelete();
            $table->string('course', 16)->default('main');
            $table->timestampTz('fired_at')->nullable();
            $table->string('offline_nonce', 64)->nullable()->unique();
            $table->foreignId('parent_split_id')->nullable()->constrained('pos_charges')->nullOnDelete();
        });

        // Existing charges predate courses: everything fired as mains.
        DB::table('pos_charges')->update(['course' => 'main']);
    }

    public function down(): void
    {
        Schema::table('pos_charges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dining_table_id');
            $table->dropConstrainedForeignId('parent_split_id');
            $table->dropUnique(['offline_nonce']);
            $table->dropColumn(['course', 'fired_at', 'offline_nonce']);
        });
    }
};
