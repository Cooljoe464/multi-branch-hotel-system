<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_provider_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 32);
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rate_plan_id')->constrained()->cascadeOnDelete();
            $table->string('channel_room_code', 64);
            $table->string('channel_rate_code', 64);
            $table->timestamps();

            $table->unique(['channel', 'channel_room_code', 'channel_rate_code']);
            $table->index(['branch_id', 'channel']);
        });

        Schema::create('channel_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 32);
            $table->string('kind', 16);
            $table->json('payload');
            $table->string('idempotency_key', 64)->unique();
            $table->string('status', 16)->default('queued');
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at']);
            $table->index(['branch_id', 'channel']);
        });

        Schema::create('channel_reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_provider_id')->constrained()->cascadeOnDelete();
            $table->date('stay_date');
            $table->json('diff')->nullable();
            $table->string('status', 16)->default('ok');
            $table->timestamps();

            $table->unique(['branch_id', 'channel_provider_id', 'stay_date']);
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_reconciliation_runs');
        Schema::dropIfExists('channel_messages');
        Schema::dropIfExists('channel_mappings');
    }
};
