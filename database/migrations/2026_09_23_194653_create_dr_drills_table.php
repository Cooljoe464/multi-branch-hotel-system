<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dr_drills', function (Blueprint $table) {
            $table->id();
            $table->date('drill_date');
            $table->string('mode', 16)->default('dry-run');
            $table->string('status', 16)->default('passed');
            $table->integer('rto_minutes')->nullable();
            $table->integer('rpo_minutes')->nullable();
            $table->json('checks')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'drill_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dr_drills');
    }
};
