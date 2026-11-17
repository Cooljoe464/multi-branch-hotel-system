<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tax_profiles', function (Blueprint $table): void {
            $table->date('effective_from')->nullable()->after('active');
            $table->date('effective_until')->nullable()->after('effective_from');
            $table->index(['branch_id', 'effective_from', 'effective_until']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tax_profiles', function (Blueprint $table): void {
            $table->dropIndex('tax_profiles_branch_id_effective_from_effective_until_index');
            $table->dropColumn(['effective_from', 'effective_until']);
        });
    }
};
