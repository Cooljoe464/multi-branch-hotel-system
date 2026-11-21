<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('sso_issuer')->nullable()->index();
            $table->string('sso_subject')->nullable();
        });

        DB::statement('CREATE UNIQUE INDEX sso_issuer_subject_idx ON users (sso_issuer, sso_subject)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sso_issuer_subject_idx');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['sso_issuer', 'sso_subject']);
        });
    }
};
