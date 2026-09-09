<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->string('category'); // room, pillow, minibar, newspaper, wake_up_call, etc.
            $table->string('key');
            $table->text('value');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['guest_id', 'category', 'key']);
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_preferences');
    }
};
