<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->nullable()->constrained()->nullOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('rate_override')->nullable(); // cents, null = use base_rate
            $table->integer('mlos')->nullable(); // Minimum Length of Stay
            $table->boolean('cta')->default(false); // Closed to Arrival
            $table->boolean('ctd')->default(false); // Closed to Departure
            $table->boolean('is_active')->default(true);
            $table->string('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['branch_id', 'start_date', 'end_date']);
            $table->index(['branch_id', 'room_type_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_overrides');
    }
};
