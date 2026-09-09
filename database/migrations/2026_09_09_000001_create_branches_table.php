<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('slug')->unique();
            $table->text('address')->nullable();
            $table->string('city');
            $table->string('state')->nullable();
            $table->string('country', 2)->default('US');
            $table->string('postal_code')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('timezone')->default('America/New_York');
            $table->string('currency_code', 3)->default('USD');
            $table->string('currency_symbol')->default('$');
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->string('tax_label')->default('Tax');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_primary')->default(false);
            $table->json('settings')->nullable();
            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('is_active');
            $table->index('is_primary');
            $table->index('country');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
