<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->text('description')->nullable();
            $table->integer('base_rate');
            $table->integer('max_occupancy')->default(2);
            $table->integer('bed_count')->default(1);
            $table->string('bed_type')->default('queen');
            $table->boolean('is_active')->default(true);
            $table->json('amenities')->nullable();
            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['branch_id', 'code']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_types');
    }
};
