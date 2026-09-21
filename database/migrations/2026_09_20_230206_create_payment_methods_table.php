<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('owner');
            $table->string('driver', 32);
            $table->string('token', 128);
            $table->string('brand', 16)->nullable();
            $table->string('last4', 4)->nullable();
            $table->date('exp_date')->nullable();
            $table->timestamps();

            $table->unique(['driver', 'token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
