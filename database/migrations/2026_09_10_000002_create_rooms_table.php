<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->string('number');
            $table->string('floor')->nullable();
            $table->string('wing')->nullable();
            $table->string('status')->default('available');
            $table->boolean('is_accessible')->default(false);
            $table->boolean('is_smoking')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['branch_id', 'number']);
            $table->index('status');
            $table->index('is_active');
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
