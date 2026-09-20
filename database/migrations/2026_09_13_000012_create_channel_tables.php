<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('api_key')->nullable();
            $table->string('api_secret')->nullable();
            $table->string('property_id_external')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'provider']);
        });

        Schema::create('channel_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rate_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->string('channel_rate_code')->nullable();
            $table->string('external_rate_id')->nullable();
            $table->boolean('is_synced')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('channel_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel_booking_id')->unique();
            $table->json('raw_payload');
            $table->string('sync_status')->default('pending');
            $table->timestamps();
        });

        Schema::create('audit_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('flag_type');
            $table->string('severity');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('description');
            $table->json('evidence')->nullable();
            $table->boolean('is_reviewed')->default(false);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'flag_type']);
            $table->index(['branch_id', 'is_reviewed']);
        });

        Schema::create('city_ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('company_name');
            $table->string('contact_name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->integer('credit_limit')->default(0);
            $table->integer('balance_owing')->default(0);
            $table->integer('payment_terms_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'is_active']);
        });

        Schema::create('city_ledger_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_ledger_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('folio_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->integer('amount');
            $table->string('reference')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['city_ledger_account_id', 'created_at']);
        });

        Schema::create('menu_item_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kitchen_station_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['menu_item_id', 'kitchen_station_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_stations');
        Schema::dropIfExists('city_ledger_transactions');
        Schema::dropIfExists('city_ledger_accounts');
        Schema::dropIfExists('audit_flags');
        Schema::dropIfExists('channel_reservations');
        Schema::dropIfExists('channel_rates');
        Schema::dropIfExists('channel_providers');
    }
};
