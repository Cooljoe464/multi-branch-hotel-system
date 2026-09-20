<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('door_lock_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gateway_id')->constrained('door_lock_gateways')->cascadeOnDelete();
            $table->string('action'); // issue_key, revoke_key, extend_key
            $table->string('credential_id')->nullable();
            $table->text('pin_code')->nullable();
            $table->timestamp('valid_from');
            $table->timestamp('valid_until');
            $table->string('status')->default('pending'); // pending, success, failed, revoked
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index('reservation_id');
            $table->index('room_id');
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('door_lock_audit_logs');
    }
};
