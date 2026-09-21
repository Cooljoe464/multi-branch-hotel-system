<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_exemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('exemptable');
            $table->string('component_code', 32);
            $table->string('reason', 128);
            $table->timestamps();

            $table->index(['branch_id', 'component_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_exemptions');
    }
};
