<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotspot_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('code', 32);
            $table->bigInteger('price_minor')->default(0);
            $table->unsignedInteger('rate_up_kbps')->default(2048);
            $table->unsignedInteger('rate_down_kbps')->default(4096);
            $table->unsignedInteger('quota_mb')->nullable();
            $table->unsignedInteger('duration_mins')->nullable();
            $table->unsignedTinyInteger('device_limit')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'code']);
            $table->index(['branch_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotspot_tiers');
    }
};
