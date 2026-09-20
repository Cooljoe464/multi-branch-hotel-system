<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kot_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_charge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('outlet');
            $table->string('item_name');
            $table->integer('quantity')->default(1);
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();
            $table->string('priority')->default('normal');
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('outlet');
            $table->index(['branch_id', 'status']);
            $table->index('pos_charge_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kot_items');
    }
};
