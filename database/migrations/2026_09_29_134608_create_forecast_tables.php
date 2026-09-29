<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demand_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('stay_date');
            $table->date('generated_on');
            $table->float('p_demand')->default(0);
            $table->bigInteger('expected_rooms')->default(0);
            $table->json('features')->nullable();
            $table->string('model_version', 32)->default('heuristic-v1');
            $table->timestamps();

            $table->unique(['branch_id', 'stay_date', 'generated_on']);
            $table->index(['branch_id', 'stay_date']);
        });

        Schema::create('price_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->date('stay_date');
            $table->bigInteger('recommended_minor')->default(0);
            $table->bigInteger('current_minor')->default(0);
            $table->string('status', 16)->default('proposed');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'stay_date', 'status']);
        });

        Schema::table('room_types', function (Blueprint $table) {
            $table->bigInteger('floor_minor')->nullable()->after('base_rate');
        });

        DB::statement('UPDATE room_types SET floor_minor = base_rate / 2 WHERE floor_minor IS NULL');
    }

    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn('floor_minor');
        });

        Schema::dropIfExists('price_recommendations');
        Schema::dropIfExists('demand_forecasts');
    }
};
