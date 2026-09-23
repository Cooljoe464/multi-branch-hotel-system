<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // External integrators (OTA, POS, corporate travel). A null
        // branch_id means multi-property: the token carries per-branch
        // abilities instead of a single lock.
        Schema::create('api_consumers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128);
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->json('scopes')->nullable();
            $table->json('branch_ids')->nullable();
            $table->string('webhook_url', 256)->nullable();
            $table->string('webhook_secret', 128)->nullable();
            $table->string('webhook_previous_secret', 128)->nullable();
            $table->timestampTz('webhook_grace_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['branch_id', 'is_active']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_consumer_id')->constrained()->cascadeOnDelete();
            $table->string('event', 64);
            $table->json('payload');
            $table->string('signature', 128);
            $table->string('status', 16)->default('pending');
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at']);
            $table->index(['api_consumer_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('api_consumers');
    }
};
