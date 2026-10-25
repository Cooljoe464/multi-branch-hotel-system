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
        Schema::create('branch_secrets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 128);
            $table->text('value');
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'key']);
        });

        // Platform-scoped secrets (branch_id NULL) are unique on key alone.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX branch_secrets_platform_key_unique ON branch_secrets (key) WHERE branch_id IS NULL',
            );
        }

        $this->migrateLegacyWhatsappSecrets();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_secrets');
    }

    /**
     * Lift the encrypted WhatsApp tokens that used to live inside
     * branches.settings.whatsapp into the dedicated secret store.
     */
    private function migrateLegacyWhatsappSecrets(): void
    {
        $legacyKeys = [
            'access_token_enc' => 'whatsapp.access_token',
            'app_secret_enc' => 'whatsapp.app_secret',
        ];

        $now = now();

        DB::table('branches')
            ->whereNotNull('settings')
            ->orderBy('id')
            ->chunkById(100, function ($branches) use ($legacyKeys, $now) {
                foreach ($branches as $branch) {
                    $rawSettings = $branch->settings;

                    if (! is_string($rawSettings) || $rawSettings === '') {
                        continue;
                    }

                    $settings = json_decode($rawSettings, true);

                    if (! is_array($settings)) {
                        continue;
                    }

                    $whatsapp = $settings['whatsapp'] ?? null;

                    if (! is_array($whatsapp)) {
                        continue;
                    }

                    foreach ($legacyKeys as $legacyKey => $secretKey) {
                        $value = $whatsapp[$legacyKey] ?? null;

                        if (! is_string($value) || $value === '') {
                            continue;
                        }

                        DB::table('branch_secrets')->upsert(
                            [
                                'branch_id' => $branch->id,
                                'key' => $secretKey,
                                'value' => $value,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ],
                            ['branch_id', 'key'],
                            ['value', 'updated_at'],
                        );

                        unset($whatsapp[$legacyKey]);
                    }

                    $settings['whatsapp'] = $whatsapp;

                    DB::table('branches')
                        ->where('id', $branch->id)
                        ->update(['settings' => json_encode($settings)]);
                }
            });
    }
};
