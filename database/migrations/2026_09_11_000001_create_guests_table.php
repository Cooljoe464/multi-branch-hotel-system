<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guests', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('nationality')->nullable();
            $table->string('id_type')->nullable(); // passport, drivers_license, national_id
            $table->string('id_number')->nullable();
            $table->string('company')->nullable();
            $table->string('job_title')->nullable();
            $table->string('vip_status')->default('none'); // none, silver, gold, platinum, diamond
            $table->integer('total_stays')->default(0);
            $table->integer('total_nights')->default(0);
            $table->integer('total_spent')->default(0); // in cents
            $table->string('currency_code')->default('USD');
            $table->string('preferred_language')->default('en');
            $table->string('preferred_currency')->default('USD');
            $table->text('dietary_restrictions')->nullable();
            $table->text('special_notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_stayed_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('email');
            $table->index(['last_name', 'first_name']);
            $table->index('vip_status');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guests');
    }
};
