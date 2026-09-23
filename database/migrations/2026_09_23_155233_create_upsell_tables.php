<?php

use App\Models\Branch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upsell_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->json('rules')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['branch_id', 'kind', 'active']);
        });

        Schema::create('upsell_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('upsell_offer_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('fee_minor')->default(0);
            $table->string('idempotency_key', 64)->unique();
            $table->timestampTz('expires_at')->nullable();
            $table->timestamps();

            $table->index(['reservation_id', 'upsell_offer_id']);
        });

        // Seed priced-but-inactive standard offers per branch; managers
        // price and activate them.
        foreach (Branch::orderBy('id')->pluck('id') as $branchId) {
            $now = now();

            foreach (['early_checkin', 'late_checkout', 'upgrade'] as $kind) {
                DB::table('upsell_offers')->updateOrInsert(
                    ['branch_id' => $branchId, 'kind' => $kind],
                    [
                        'rules' => json_encode(['fee_minor' => 0, 'cutoff_hour' => 12, 'inventory_guard' => true]),
                        'active' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('upsell_acceptances');
        Schema::dropIfExists('upsell_offers');
    }
};
