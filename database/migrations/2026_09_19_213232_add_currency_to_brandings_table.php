<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brandings', function (Blueprint $table) {
            $table->string('currency_code', 3)->default('NGN')->after('app_name');
            $table->string('currency_symbol')->default('₦')->after('currency_code');
        });
    }

    public function down(): void
    {
        Schema::table('brandings', function (Blueprint $table) {
            $table->dropColumn(['currency_code', 'currency_symbol']);
        });
    }
};
