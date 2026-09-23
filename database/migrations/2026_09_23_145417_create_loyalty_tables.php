<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32)->unique();
            $table->integer('threshold_nights')->default(0);
            $table->integer('earn_bps')->default(10000);
            $table->timestamps();
        });

        Schema::create('loyalty_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_id')->unique()->constrained()->cascadeOnDelete();
            $table->bigInteger('points')->default(0);
            $table->string('tier', 32)->default('member');
            $table->timestamps();
        });

        Schema::create('loyalty_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_account_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('delta');
            $table->string('reason', 64);
            $table->nullableMorphs('source');
            $table->string('idempotency_key', 64)->unique();
            $table->timestamps();

            $table->index(['loyalty_account_id', 'created_at']);
        });

        // Standard ladder; branches share tiers globally.
        $now = now();

        foreach ([
            ['member', 0, 10000],
            ['silver', 10, 11000],
            ['gold', 25, 12500],
            ['platinum', 50, 15000],
        ] as [$name, $nights, $bps]) {
            DB::table('loyalty_tiers')->updateOrInsert(
                ['name' => $name],
                ['threshold_nights' => $nights, 'earn_bps' => $bps, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_ledger');
        Schema::dropIfExists('loyalty_accounts');
        Schema::dropIfExists('loyalty_tiers');
    }
};
