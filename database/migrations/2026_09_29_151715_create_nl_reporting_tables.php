<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 128);
            $table->text('nl');
            $table->text('sql');
            $table->boolean('shared')->default(false);
            $table->timestamps();

            $table->index(['branch_id', 'shared']);
        });

        Schema::create('nl_query_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('query_key', 64)->unique();
            $table->string('prompt_hash', 64);
            $table->string('sql_hash', 64);
            $table->string('status', 16)->default('pending');
            $table->integer('rows')->default(0);
            $table->integer('duration_ms')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nl_query_logs');
        Schema::dropIfExists('report_queries');
    }
};
