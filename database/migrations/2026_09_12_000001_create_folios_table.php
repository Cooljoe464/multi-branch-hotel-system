<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_folio_id')->nullable()->constrained('folios')->nullOnDelete();
            $table->string('folio_number')->unique();
            $table->string('type')->default('individual');
            $table->string('status')->default('open');
            $table->string('description')->nullable();
            $table->integer('balance')->default(0);
            $table->boolean('is_settled')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
            $table->index('type');
            $table->index(['branch_id', 'status']);
            $table->index('reservation_id');
            $table->index('parent_folio_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folios');
    }
};
