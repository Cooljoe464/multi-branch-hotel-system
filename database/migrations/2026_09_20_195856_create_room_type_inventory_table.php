<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_type_inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->date('stay_date');
            $table->integer('total_rooms')->default(0);
            $table->integer('sold')->default(0);
            $table->integer('blocked')->default(0);
            $table->integer('overbooking_limit')->default(0);
            $table->timestamps();

            $table->unique(['room_type_id', 'stay_date']);
            $table->index(['branch_id', 'stay_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_type_inventory');
    }
};
