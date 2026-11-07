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
        Schema::table('branches', function (Blueprint $table) {
            $table->foreignId('tenant_id')
                ->nullable()
                ->constrained('tenants')
                ->restrictOnDelete();
            $table->index('tenant_id');
        });

        DB::table('branches')
            ->select(['id', 'name', 'email', 'country', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->chunkById(100, function ($branches): void {
                foreach ($branches as $branch) {
                    $createdAt = $branch->created_at ?? now();
                    $tenantId = DB::table('tenants')->insertGetId([
                        'name' => $branch->name,
                        'slug' => 'legacy-property-'.$branch->id,
                        'billing_email' => $branch->email,
                        'country' => $branch->country,
                        'metadata' => json_encode([
                            'source' => 'legacy_branch_backfill',
                            'legal_name_verified' => false,
                        ], JSON_THROW_ON_ERROR),
                        'created_at' => $createdAt,
                        'updated_at' => $branch->updated_at ?? $createdAt,
                    ]);

                    DB::table('branches')
                        ->where('id', $branch->id)
                        ->update(['tenant_id' => $tenantId]);
                }
            });

        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Tenant ownership cannot be rolled back without losing property ownership data.');
    }
};
