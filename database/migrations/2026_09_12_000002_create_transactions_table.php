<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folio_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('category');
            $table->string('description');
            $table->integer('amount');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_taxable')->default(true);
            $table->integer('tax_amount')->default(0);
            $table->boolean('is_voided')->default(false);
            $table->timestamp('voided_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('category');
            $table->index(['reference_type', 'reference_id']);
            $table->index('folio_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
