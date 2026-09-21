<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('folio_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 32)->default('firs');
            $table->string('status', 16)->default('pending');
            $table->string('irn', 128)->nullable();
            $table->json('payload')->nullable();
            $table->text('last_error')->nullable();
            $table->integer('attempts')->default(0);
            $table->string('idempotency_key', 64)->unique();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_documents');
    }
};
