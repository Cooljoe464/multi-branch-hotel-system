<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reservations')) {
            Schema::table('reservations', function (Blueprint $table) {
                if (! Schema::hasIndex('reservations', 'reservations_room_type_id_index')) {
                    $table->index('room_type_id');
                }
                if (! Schema::hasIndex('reservations', 'reservations_guest_id_index')) {
                    $table->index('guest_id');
                }
                if (! Schema::hasIndex('reservations', 'reservations_confirmation_number_index')) {
                    $table->index('confirmation_number');
                }
            });
        }

        if (Schema::hasTable('channel_rates')) {
            Schema::table('channel_rates', function (Blueprint $table) {
                if (! Schema::hasIndex('channel_rates', 'channel_rates_channel_provider_id_index')) {
                    $table->index('channel_provider_id');
                }
                if (! Schema::hasIndex('channel_rates', 'channel_rates_room_type_id_index')) {
                    $table->index('room_type_id');
                }
                if (! Schema::hasIndex('channel_rates', 'channel_rates_rate_plan_id_index')) {
                    $table->index('rate_plan_id');
                }
            });
        }

        if (Schema::hasTable('transactions')) {
            Schema::table('transactions', function (Blueprint $table) {
                if (! Schema::hasIndex('transactions', 'transactions_folio_id_index')) {
                    $table->index('folio_id');
                }
                if (! Schema::hasIndex('transactions', 'transactions_type_index')) {
                    $table->index('type');
                }
            });
        }

        if (Schema::hasTable('maintenance_tickets')) {
            Schema::table('maintenance_tickets', function (Blueprint $table) {
                if (! Schema::hasIndex('maintenance_tickets', 'maintenance_tickets_branch_id_index')) {
                    $table->index('branch_id');
                }
                if (! Schema::hasIndex('maintenance_tickets', 'maintenance_tickets_status_index')) {
                    $table->index('status');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('reservations')) {
            Schema::table('reservations', function (Blueprint $table) {
                foreach (['reservations_room_type_id_index', 'reservations_guest_id_index', 'reservations_confirmation_number_index'] as $index) {
                    if (Schema::hasIndex('reservations', $index)) {
                        $table->dropIndex($index);
                    }
                }
            });
        }

        if (Schema::hasTable('channel_rates')) {
            Schema::table('channel_rates', function (Blueprint $table) {
                foreach (['channel_rates_channel_provider_id_index', 'channel_rates_room_type_id_index', 'channel_rates_rate_plan_id_index'] as $index) {
                    if (Schema::hasIndex('channel_rates', $index)) {
                        $table->dropIndex($index);
                    }
                }
            });
        }

        if (Schema::hasTable('transactions')) {
            Schema::table('transactions', function (Blueprint $table) {
                foreach (['transactions_folio_id_index', 'transactions_type_index'] as $index) {
                    if (Schema::hasIndex('transactions', $index)) {
                        $table->dropIndex($index);
                    }
                }
            });
        }

        if (Schema::hasTable('maintenance_tickets')) {
            Schema::table('maintenance_tickets', function (Blueprint $table) {
                foreach (['maintenance_tickets_branch_id_index', 'maintenance_tickets_status_index'] as $index) {
                    if (Schema::hasIndex('maintenance_tickets', $index)) {
                        $table->dropIndex($index);
                    }
                }
            });
        }
    }
};
