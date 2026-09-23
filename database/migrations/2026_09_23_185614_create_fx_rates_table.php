<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Daily FX reference rates. Rate stored as integer micros:
        // 1 unit of base buys rate_micro / 1_000_000 units of quote.
        // Integers keep conversions exact; mulDiv does the math.
        Schema::create('fx_rates', function (Blueprint $table) {
            $table->id();
            $table->string('base_code', 3);
            $table->string('quote_code', 3);
            $table->date('rate_date');
            $table->unsignedBigInteger('rate_micro');
            $table->string('source', 32)->default('manual');
            $table->timestamps();

            $table->unique(['base_code', 'quote_code', 'rate_date']);
            $table->index(['base_code', 'quote_code', 'rate_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rates');
    }
};
