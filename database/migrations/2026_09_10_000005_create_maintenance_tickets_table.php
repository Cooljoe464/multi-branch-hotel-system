<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reported_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ticket_number');
            $table->string('category');
            $table->string('priority')->default('normal');
            $table->string('status')->default('open');
            $table->string('title');
            $table->text('description');
            $table->text('resolution_notes')->nullable();
            $table->boolean('is_room_locked')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('estimated_cost')->nullable();
            $table->integer('actual_cost')->nullable();
            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['branch_id', 'ticket_number']);
            $table->index('status');
            $table->index('category');
            $table->index('priority');
            $table->index(['branch_id', 'status']);
            $table->index('room_id');
            $table->index('reported_by');
            $table->index('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_tickets');
    }
};
