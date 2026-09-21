<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_nights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->date('stay_date');
            $table->timestamps();

            $table->unique(['reservation_id', 'stay_date']);
            $table->index(['room_id', 'stay_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_nights');
    }
};
