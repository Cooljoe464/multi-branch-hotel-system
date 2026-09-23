<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statutory_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->date('period_from');
            $table->date('period_to');
            $table->string('status', 16)->default('queued');
            $table->string('file_path', 256)->nullable();
            $table->string('file_hash', 128)->nullable();
            $table->json('summary')->nullable();
            $table->string('idempotency_key', 96)->unique();
            $table->foreignId('generated_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statutory_reports');
    }
};
