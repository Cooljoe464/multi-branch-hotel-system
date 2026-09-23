<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->foreignId('to_room_id')->constrained('rooms')->cascadeOnDelete();
            $table->timestampTz('moved_at');
            $table->foreignId('moved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->boolean('key_reissued')->default(false);
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['reservation_id', 'moved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_moves');
    }
};
