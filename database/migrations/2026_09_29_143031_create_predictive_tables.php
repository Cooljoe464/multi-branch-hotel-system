<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_health_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->date('scored_on');
            $table->float('failure_prob')->default(0);
            $table->json('signals')->nullable();
            $table->timestamps();

            $table->unique(['asset_id', 'scored_on']);
            $table->index(['scored_on', 'failure_prob']);
        });

        Schema::create('hk_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->json('assignments')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamps();

            $table->unique(['branch_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hk_schedules');
        Schema::dropIfExists('asset_health_scores');
    }
};
