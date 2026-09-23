<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name', 128);
            $table->string('category', 32)->default('other');
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->date('installed_on')->nullable();
            $table->json('pm_schedule')->nullable();
            $table->date('last_pm_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
