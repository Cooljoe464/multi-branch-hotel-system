<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anomaly_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 64);
            $table->json('params')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'code']);
            $table->index(['active', 'branch_id']);
        });

        Schema::create('anomaly_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('rule_code', 64);
            $table->nullableMorphs('subject');
            $table->float('score')->default(0);
            $table->string('status', 16)->default('open');
            $table->json('evidence')->nullable();
            $table->timestamps();

            $table->unique(['rule_code', 'subject_type', 'subject_id']);
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anomaly_findings');
        Schema::dropIfExists('anomaly_rules');
    }
};
