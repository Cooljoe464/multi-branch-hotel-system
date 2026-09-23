<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16);
            $table->json('account_map')->nullable();
            $table->binary('oauth_enc')->nullable();
            $table->boolean('sandbox')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'provider']);
        });

        Schema::create('accounting_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->string('provider', 16);
            $table->string('status', 16)->default('pending');
            $table->string('external_id', 128)->nullable();
            $table->json('totals')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'business_date', 'provider']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_exports');
        Schema::dropIfExists('accounting_links');
    }
};
