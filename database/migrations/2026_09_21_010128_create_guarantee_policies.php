<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guarantee_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rate_plan_id')->nullable()->constrained('rate_plans')->nullOnDelete();
            $table->string('kind', 32)->default('deposit_schedule');
            $table->json('rules')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['branch_id', 'is_active']);
            $table->index(['branch_id', 'rate_plan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guarantee_policies');
    }
};
