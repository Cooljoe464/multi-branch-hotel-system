<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trial_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->json('totals');
            $table->boolean('balanced')->default(false);
            $table->timestamps();

            $table->unique(['branch_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trial_balances');
    }
};
