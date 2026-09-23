<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telecom_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('destination_prefix', 16);
            $table->bigInteger('rate_minor_per_min')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['branch_id', 'is_active']);
        });

        Schema::create('call_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('extension', 16);
            $table->string('destination', 32);
            $table->bigInteger('duration_secs')->default(0);
            $table->bigInteger('charge_minor')->default(0);
            $table->string('cdr_id', 64)->unique();
            $table->timestamps();

            $table->index(['branch_id', 'reservation_id']);
        });

        Schema::create('wifi_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('voucher', 32)->unique();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'reservation_id']);
        });

        Schema::create('mobile_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->string('device_id', 64);
            $table->binary('key_enc')->nullable();
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_to')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['reservation_id', 'device_id', 'status']);
            $table->index(['reservation_id', 'status']);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->string('cdr_secret', 128)->nullable()->after('currency_symbol');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('cdr_secret');
        });

        Schema::dropIfExists('mobile_keys');
        Schema::dropIfExists('wifi_sessions');
        Schema::dropIfExists('call_records');
        Schema::dropIfExists('telecom_rates');
    }
};
