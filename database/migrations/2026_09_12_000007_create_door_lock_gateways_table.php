<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('door_lock_gateways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('provider'); // assa_abloy, salto, dormakaba
            $table->string('api_base_url');
            $table->text('api_key');
            $table->text('api_secret')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique('branch_id');
            $table->index('provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('door_lock_gateways');
    }
};
