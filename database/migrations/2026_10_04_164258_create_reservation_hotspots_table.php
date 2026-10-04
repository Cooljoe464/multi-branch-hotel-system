<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_hotspots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hotspot_tier_id')->constrained()->nullOnDelete();
            $table->bigInteger('fee_minor')->default(0);
            $table->string('voucher', 32)->nullable();
            $table->foreignId('folio_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->timestamps();

            $table->unique(['reservation_id']);
            $table->index(['hotspot_tier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_hotspots');
    }
};
