<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->json('overbooking_policy')->nullable()->after('current_business_date');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->boolean('overbooked')->default(false)->after('business_date');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('overbooked');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('overbooking_policy');
        });
    }
};
