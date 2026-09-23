<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 32);
            $table->string('purpose', 64);
            $table->boolean('granted');
            $table->timestampTz('at')->nullable();
            $table->timestamps();

            $table->index(['guest_id', 'channel']);
        });

        Schema::create('post_stay_surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->unique()->constrained()->cascadeOnDelete();
            $table->integer('nps')->nullable();
            $table->json('answers')->nullable();
            $table->float('sentiment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_stay_surveys');
        Schema::dropIfExists('consents');
    }
};
