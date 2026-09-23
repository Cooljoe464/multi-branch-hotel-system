<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name', 128);
            $table->string('code', 32);
            $table->date('cutoff_date');
            $table->integer('attrition_pct')->default(0);
            $table->string('status', 16)->default('tentative');
            $table->foreignId('master_folio_id')->nullable()->constrained('folios')->nullOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'code']);
            $table->index(['branch_id', 'status']);
        });

        Schema::create('group_block_nights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_block_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->date('stay_date');
            $table->integer('blocked')->default(0);
            $table->integer('picked_up')->default(0);
            $table->timestamps();

            $table->unique(['group_block_id', 'room_type_id', 'stay_date']);
        });

        Schema::create('function_spaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->integer('capacity')->nullable();
            $table->timestamps();

            $table->index(['branch_id']);
        });

        Schema::create('banquet_event_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_block_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('function_space_id')->constrained()->cascadeOnDelete();
            $table->date('event_date');
            $table->json('schedule')->nullable();
            $table->bigInteger('agreed_total_minor')->default(0);
            $table->string('status', 16)->default('draft');
            $table->timestamps();

            $table->index(['branch_id', 'event_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banquet_event_orders');
        Schema::dropIfExists('function_spaces');
        Schema::dropIfExists('group_block_nights');
        Schema::dropIfExists('group_blocks');
    }
};
