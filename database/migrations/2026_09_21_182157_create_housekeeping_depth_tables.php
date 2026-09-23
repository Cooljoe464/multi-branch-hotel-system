<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('housekeeping_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->integer('credits')->default(10);
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('open');
            $table->integer('inspection_score')->nullable();
            $table->json('photo_paths')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['assignee_id', 'status']);
        });

        Schema::create('minibar_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('folio_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->json('items');
            $table->bigInteger('total_minor');
            $table->string('idempotency_key', 64)->unique();
            $table->timestamps();

            $table->index(['branch_id', 'room_id']);
        });

        Schema::create('lost_found_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 256);
            $table->string('status', 16)->default('logged');
            $table->json('claim')->nullable();
            $table->foreignId('logged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });

        Schema::create('room_outs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->index(['room_id', 'from_date', 'to_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_outs');
        Schema::dropIfExists('lost_found_items');
        Schema::dropIfExists('minibar_postings');
        Schema::dropIfExists('housekeeping_tasks');
    }
};
