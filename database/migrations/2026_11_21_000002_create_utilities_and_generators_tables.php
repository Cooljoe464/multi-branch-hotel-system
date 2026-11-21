<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('model')->nullable();
            $table->integer('kva_rating')->default(100);
            $table->decimal('fuel_capacity_liters', 8, 2)->default(500);
            $table->decimal('liters_per_hour_rated', 8, 2)->default(25);
            $table->decimal('current_meter_hours', 10, 2)->default(0);
            $table->string('status', 32)->default('standby');
            $table->timestamp('last_serviced_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });

        Schema::create('fuel_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generator_id')->nullable()->constrained()->nullOnDelete();
            $table->string('log_type', 32); // delivery, dipstick, transfer
            $table->decimal('liters', 8, 2);
            $table->bigInteger('cost_per_liter_minor')->default(0);
            $table->bigInteger('total_cost_minor')->default(0);
            $table->string('supplier_name')->nullable();
            $table->string('invoice_number')->nullable();
            $table->decimal('dipstick_level_cm', 6, 2)->nullable();
            $table->foreignId('logged_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('logged_at');
            $table->timestamps();

            $table->index(['branch_id', 'logged_at']);
        });

        Schema::create('generator_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generator_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('stopped_at')->nullable();
            $table->decimal('start_meter_hours', 10, 2);
            $table->decimal('end_meter_hours', 10, 2)->nullable();
            $table->decimal('run_hours', 8, 2)->nullable();
            $table->decimal('fuel_consumed_liters', 8, 2)->nullable();
            $table->decimal('kwh_generated', 10, 2)->nullable();
            $table->string('reason', 64)->default('grid_outage');
            $table->foreignId('operator_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['generator_id', 'started_at']);
        });

        Schema::create('utility_meters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('meter_number');
            $table->string('type', 32); // grid_kwh, water_m3, gas
            $table->bigInteger('tariff_rate_minor')->default(0);
            $table->decimal('current_reading', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['branch_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utility_meters');
        Schema::dropIfExists('generator_runs');
        Schema::dropIfExists('fuel_logs');
        Schema::dropIfExists('generators');
    }
};
