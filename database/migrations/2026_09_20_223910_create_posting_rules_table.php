<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posting_rules', function (Blueprint $table) {
            $table->id();
            $table->string('event', 64)->unique();
            $table->string('debit_account', 32);
            $table->string('credit_account', 32);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posting_rules');
    }
};
