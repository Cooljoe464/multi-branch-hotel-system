<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spa_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('category', 64)->default('massage');
            $table->integer('duration_minutes')->default(60);
            $table->bigInteger('price_minor')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['branch_id', 'is_active']);
        });

        Schema::create('spa_treatment_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('room_type', 64)->default('single');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['branch_id', 'is_active']);
        });

        Schema::create('spa_therapists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('specialties')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['branch_id', 'is_active']);
        });

        Schema::create('spa_appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->string('guest_name');
            $table->foreignId('spa_service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spa_treatment_room_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('spa_therapist_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('scheduled_at');
            $table->integer('duration_minutes')->default(60);
            $table->bigInteger('price_minor')->default(0);
            $table->string('status', 32)->default('scheduled'); // scheduled, in_progress, completed, cancelled, no_show
            $table->foreignId('folio_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'scheduled_at']);
            $table->index(['spa_therapist_id', 'scheduled_at']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spa_appointments');
        Schema::dropIfExists('spa_therapists');
        Schema::dropIfExists('spa_treatment_rooms');
        Schema::dropIfExists('spa_services');
    }
};
