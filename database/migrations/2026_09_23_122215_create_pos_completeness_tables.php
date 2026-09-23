<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dining_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('code', 16);
            $table->string('shape', 16)->default('square');
            $table->integer('seats')->default(2);
            $table->json('position')->nullable();
            $table->string('status', 16)->default('free');
            $table->timestamps();

            $table->unique(['outlet_id', 'code']);
            $table->index(['branch_id', 'status']);
        });

        Schema::create('pos_modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->bigInteger('price_delta_minor')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['branch_id', 'active']);
        });

        Schema::create('happy_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('cron_window', 64);
            $table->integer('discount_bps')->default(0);
            $table->json('applies_to')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['outlet_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('happy_hours');
        Schema::dropIfExists('pos_modifiers');
        Schema::dropIfExists('dining_tables');
    }
};
