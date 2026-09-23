<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_manifests', function (Blueprint $table) {
            $table->id();
            $table->date('business_date')->unique();
            $table->json('files')->nullable();
            $table->json('row_counts')->nullable();
            $table->json('checksums')->nullable();
            $table->string('status', 16)->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_manifests');
    }
};
