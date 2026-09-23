<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dsar_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('status', 16)->default('open');
            $table->json('result')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('fulfilled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['guest_id', 'status']);
            $table->index(['branch_id', 'status']);
        });

        Schema::create('retention_policies', function (Blueprint $table) {
            $table->id();
            $table->string('data_class', 64)->unique();
            $table->integer('retain_days');
            $table->string('action', 16)->default('anonymize');
            $table->timestamps();
        });

        // Seed sane defaults; branches override via admin.
        $now = now();

        foreach (config('gdpr.retention_defaults', []) as $class => $days) {
            DB::table('retention_policies')->updateOrInsert(
                ['data_class' => $class],
                [
                    'retain_days' => $days,
                    'action' => $class === 'folio_lines' ? 'anonymize' : 'purge',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_policies');
        Schema::dropIfExists('dsar_requests');
    }
};
