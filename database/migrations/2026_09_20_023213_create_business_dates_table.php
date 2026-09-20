<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->string('status', 16)->default('open');
            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('close_summary')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'business_date']);
            $table->index(['branch_id', 'status']);
        });

        // Exactly one open business date per branch (PostgreSQL only;
        // other drivers rely on the transactional claim in BusinessDateService).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "CREATE UNIQUE INDEX business_dates_one_open_per_branch
                 ON business_dates (branch_id) WHERE status = 'open'"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('business_dates');
    }
};
