<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('linen_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('category', 64)->default('bed_linen'); // bed_linen, bath_linen, table_linen, uniforms
            $table->integer('par_level')->default(100);
            $table->integer('current_clean')->default(0);
            $table->integer('current_dirty')->default(0);
            $table->integer('current_in_laundry')->default(0);
            $table->bigInteger('unit_cost_minor')->default(0);
            $table->timestamps();

            $table->index(['branch_id', 'category']);
        });

        Schema::create('laundry_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('batch_number', 64);
            $table->string('vendor_type', 32)->default('internal'); // internal, outsourced
            $table->string('vendor_name')->nullable();
            $table->string('status', 32)->default('sent'); // sent, in_wash, returned, reconciled
            $table->json('item_counts'); // [linen_item_id => quantity]
            $table->timestamp('dispatched_at');
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('dispatched_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });

        Schema::create('linen_discards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('linen_item_id')->constrained('linen_items')->cascadeOnDelete();
            $table->integer('quantity');
            $table->string('reason', 64); // torn, stained, burn, worn_out, missing
            $table->bigInteger('unit_cost_minor')->default(0);
            $table->bigInteger('total_loss_minor')->default(0);
            $table->foreignId('approved_by')->constrained('users')->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linen_discards');
        Schema::dropIfExists('laundry_batches');
        Schema::dropIfExists('linen_items');
    }
};
