<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('concierge_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 16)->default('whatsapp');
            $table->string('status', 16)->default('active');
            $table->string('whatsapp_wa_id', 32);
            $table->string('phone_number_id', 64)->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('handoff_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'whatsapp_wa_id']);
            $table->index(['branch_id', 'status']);
            $table->index(['phone_number_id']);
        });

        Schema::create('concierge_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('concierge_thread_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->text('body');
            $table->json('tool_calls')->nullable();
            $table->json('metadata')->nullable();
            $table->string('whatsapp_message_id', 128)->nullable()->unique();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['concierge_thread_id', 'created_at']);
            $table->index(['concierge_thread_id', 'role', 'read_at']);
        });

        Schema::create('concierge_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('concierge_thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 32);
            $table->string('status', 24)->default('pending_guest_confirm');
            $table->json('payload')->nullable();
            $table->string('idempotency_key', 128)->unique();
            $table->timestamp('guest_confirmed_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['concierge_thread_id', 'status']);
        });

        Schema::create('concierge_kb_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('topic', 128);
            $table->text('question')->nullable();
            $table->text('answer');
            $table->json('keywords')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['branch_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('concierge_kb_entries');
        Schema::dropIfExists('concierge_actions');
        Schema::dropIfExists('concierge_messages');
        Schema::dropIfExists('concierge_threads');
    }
};
