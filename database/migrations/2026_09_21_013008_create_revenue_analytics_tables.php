<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revenue_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('stay_date');
            $table->date('snapshot_date');
            $table->bigInteger('rooms_available')->default(0);
            $table->bigInteger('rooms_sold')->default(0);
            $table->bigInteger('room_revenue_minor')->default(0);
            $table->bigInteger('total_revenue_minor')->default(0);
            $table->bigInteger('gop_expense_minor')->default(0);
            $table->json('by_segment')->nullable();
            $table->json('by_source')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'stay_date', 'snapshot_date']);
            $table->index(['branch_id', 'snapshot_date']);
        });

        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->bigInteger('room_nights_target')->default(0);
            $table->bigInteger('revenue_target_minor')->default(0);
            $table->timestamps();

            $table->unique(['branch_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
        Schema::dropIfExists('revenue_snapshots');
    }
};
