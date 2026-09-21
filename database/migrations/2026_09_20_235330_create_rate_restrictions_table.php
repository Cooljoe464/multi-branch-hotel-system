<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_restrictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rate_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->nullable()->constrained()->nullOnDelete();
            $table->date('stay_date');
            $table->integer('min_los')->nullable();
            $table->integer('max_los')->nullable();
            $table->boolean('cta')->default(false);
            $table->boolean('ctd')->default(false);
            $table->boolean('stop_sell')->default(false);
            $table->integer('min_advance_hours')->nullable();
            $table->timestamps();

            $table->unique(['rate_plan_id', 'room_type_id', 'stay_date']);
            $table->index(['branch_id', 'stay_date']);
        });

        // NULL room types (all-types rows) are distinct under the unique
        // above on PostgreSQL, so guard them with a partial index.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX rate_restrictions_all_types_unique
                 ON rate_restrictions (rate_plan_id, stay_date) WHERE room_type_id IS NULL'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS rate_restrictions_all_types_unique');
        }

        Schema::dropIfExists('rate_restrictions');
    }
};
