<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->string('status')->default('pending');
            $table->integer('rooms_posted')->default(0);
            $table->integer('total_room_revenue')->default(0);
            $table->integer('total_tax')->default(0);
            $table->integer('total_other_charges')->default(0);
            $table->integer('total_payments')->default(0);
            $table->integer('net_revenue')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('errors')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'business_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_ledgers');
    }
};
