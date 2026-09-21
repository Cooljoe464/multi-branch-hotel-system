<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('audit_outcome', 32)->nullable()->after('overbooked');
            $table->integer('no_show_fee_minor')->default(0)->after('audit_outcome');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['audit_outcome', 'no_show_fee_minor']);
        });
    }
};
