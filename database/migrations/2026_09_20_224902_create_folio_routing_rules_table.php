<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folio_routing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folio_id')->constrained()->cascadeOnDelete();
            $table->string('charge_category', 32);
            $table->foreignId('target_window_id')->constrained('folio_windows')->cascadeOnDelete();
            $table->integer('priority')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['folio_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folio_routing_rules');
    }
};
