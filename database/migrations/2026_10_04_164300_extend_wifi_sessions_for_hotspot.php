<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wifi_sessions', function (Blueprint $table) {
            $table->foreignId('hotspot_tier_id')->nullable()->after('reservation_id')->constrained()->nullOnDelete();
            $table->string('username', 64)->nullable()->after('voucher');
            $table->string('password', 64)->nullable()->after('username');
            $table->foreignId('folio_transaction_id')->nullable()->after('hotspot_tier_id')->constrained('transactions')->nullOnDelete();
            $table->bigInteger('bytes_used')->default(0)->after('revoked_at');
            $table->timestampTz('provisioned_at')->nullable()->after('bytes_used');
            $table->timestampTz('deprovisioned_at')->nullable()->after('provisioned_at');
            $table->timestampTz('last_acct_at')->nullable()->after('deprovisioned_at');
        });
    }

    public function down(): void
    {
        Schema::table('wifi_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('folio_transaction_id');
            $table->dropConstrainedForeignId('hotspot_tier_id');
            $table->dropColumn(['username', 'password', 'bytes_used', 'provisioned_at', 'deprovisioned_at', 'last_acct_at']);
        });
    }
};
