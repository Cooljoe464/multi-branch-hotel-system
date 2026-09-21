<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_profile_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('mode', 16);
            $table->integer('rate_bps');
            $table->string('applies_to', 32)->default('all');
            $table->integer('sequence')->default(0);
            $table->timestamps();

            $table->unique(['tax_profile_id', 'code', 'applies_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_components');
    }
};
