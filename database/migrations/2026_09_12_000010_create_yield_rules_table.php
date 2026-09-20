<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('yield_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('min_occupancy_pct');
            $table->integer('max_occupancy_pct');
            $table->decimal('rate_multiplier', 4, 2); // e.g., 1.25 = 25% markup
            $table->integer('mlos_override')->nullable();
            $table->boolean('cta_override')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['branch_id', 'is_active', 'priority']);
            $table->index(['branch_id', 'room_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yield_rules');
    }
};
