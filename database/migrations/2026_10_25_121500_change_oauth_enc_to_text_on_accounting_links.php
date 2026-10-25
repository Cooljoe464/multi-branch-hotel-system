<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * oauth_enc holds a Crypt ciphertext string, not raw bytes. On
     * Postgres the bytea column came back as a stream, so readTokens()
     * always decoded nothing and every link looked disconnected.
     */
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE accounting_links ALTER COLUMN oauth_enc TYPE TEXT USING convert_from(oauth_enc, 'UTF8')",
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement(
            "ALTER TABLE accounting_links ALTER COLUMN oauth_enc TYPE BYTEA USING convert_to(oauth_enc, 'UTF8')",
        );
    }
};
