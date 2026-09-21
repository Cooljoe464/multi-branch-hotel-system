<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folio_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folio_id')->constrained()->cascadeOnDelete();
            $table->string('code', 16);
            $table->string('payer_type', 16)->default('guest');
            $table->foreignId('city_ledger_account_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['folio_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folio_windows');
    }
};
