<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Snapshots are denominated in the branch currency at snapshot
        // time, so a later currency change never rewrites history.
        Schema::table('revenue_snapshots', function (Blueprint $table) {
            $table->string('currency_code', 3)->nullable()->after('branch_id');
        });

        DB::statement('
            UPDATE revenue_snapshots
            SET currency_code = (
                SELECT b.currency_code
                FROM branches b
                WHERE b.id = revenue_snapshots.branch_id
            )
            WHERE currency_code IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('revenue_snapshots', function (Blueprint $table) {
            $table->dropColumn('currency_code');
        });
    }
};
