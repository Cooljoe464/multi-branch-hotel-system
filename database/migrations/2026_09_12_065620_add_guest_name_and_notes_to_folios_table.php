<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folios', function (Blueprint $table) {
            $table->string('guest_name')->nullable()->after('description');
            $table->text('notes')->nullable()->after('guest_name');
        });
    }

    public function down(): void
    {
        Schema::table('folios', function (Blueprint $table) {
            $table->dropColumn(['guest_name', 'notes']);
        });
    }
};
