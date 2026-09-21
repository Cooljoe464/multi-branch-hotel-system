<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->foreignId('base_plan_id')->nullable()->constrained('rate_plans')->nullOnDelete();
            $table->integer('derivation_bps')->nullable();
            $table->integer('derivation_fixed_minor')->nullable();
            $table->json('package_components')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('base_plan_id');
            $table->dropColumn(['derivation_bps', 'derivation_fixed_minor', 'package_components']);
        });
    }
};
