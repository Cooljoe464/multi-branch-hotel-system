<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scope', 64);
            $table->string('key', 64);
            $table->string('status', 16)->default('in_progress');
            $table->json('request_hash')->nullable();
            $table->json('response')->nullable();
            $table->timestampTz('locked_until')->nullable();
            $table->timestamps();

            $table->unique(['scope', 'key']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
