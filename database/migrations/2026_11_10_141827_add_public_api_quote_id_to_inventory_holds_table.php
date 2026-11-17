<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inventory_holds', function (Blueprint $table): void {
            $table->foreignId('public_api_quote_id')
                ->nullable()
                ->after('api_key_id')
                ->constrained('public_api_quotes')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_holds', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('public_api_quote_id');
        });
    }
};
