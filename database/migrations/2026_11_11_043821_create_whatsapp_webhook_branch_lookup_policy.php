<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $table = DB::connection()->getQueryGrammar()->wrapTable('public.branches');

        DB::statement("DROP POLICY IF EXISTS hms_whatsapp_webhook_lookup ON {$table}");
        DB::statement(
            "CREATE POLICY hms_whatsapp_webhook_lookup ON {$table} FOR SELECT USING (
                public.branches.settings->'whatsapp'->>'verify_token' = current_setting('app.whatsapp_verify_token', true)
                OR public.branches.settings->'whatsapp'->>'phone_number_id' = current_setting('app.whatsapp_phone_number_id', true)
            )",
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $table = DB::connection()->getQueryGrammar()->wrapTable('public.branches');

        DB::statement('DROP POLICY IF EXISTS hms_whatsapp_webhook_lookup ON '.$table);
    }
};
