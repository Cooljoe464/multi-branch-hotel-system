<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tablet_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->string('confirmation_number');
            $table->string('device_id')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('wiped_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'room_id']);
            $table->index('confirmation_number');
        });

        Schema::create('tablet_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tablet_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->json('items');
            $table->integer('subtotal');
            $table->integer('tax_amount');
            $table->integer('total');
            $table->string('status');
            $table->string('payment_method');
            $table->string('payment_status');
            $table->string('payment_reference')->nullable();
            $table->json('dietary_requests')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index('tablet_session_id');
        });

        Schema::create('registration_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->string('guest_name');
            $table->string('id_type');
            $table->string('id_number');
            $table->string('id_image_url')->nullable();
            $table->string('signature_image_url')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('reservation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_cards');
        Schema::dropIfExists('tablet_orders');
        Schema::dropIfExists('tablet_sessions');
    }
};
