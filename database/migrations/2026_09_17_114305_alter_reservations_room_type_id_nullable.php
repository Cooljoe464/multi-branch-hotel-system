<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropForeign(['room_type_id']);
        });

        DB::statement('ALTER TABLE reservations ALTER COLUMN room_type_id DROP NOT NULL');

        Schema::table('reservations', function (Blueprint $table) {
            $table->foreign('room_type_id')->references('id')->on('room_types')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropForeign(['room_type_id']);
        });

        DB::statement('ALTER TABLE reservations ALTER COLUMN room_type_id SET NOT NULL');

        Schema::table('reservations', function (Blueprint $table) {
            $table->foreign('room_type_id')->references('id')->on('room_types')->cascadeOnDelete();
        });
    }
};
