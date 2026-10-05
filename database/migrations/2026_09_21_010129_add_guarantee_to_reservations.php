<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('guarantee_status', 16)->default('none');
            $table->integer('deposit_due_minor')->default(0);
            $table->integer('deposit_paid_minor')->default(0);
            $table->timestamp('cancel_deadline_at')->nullable();
            $table->timestamp('hold_expires_at')->nullable();

            $table->index(['branch_id', 'guarantee_status']);
            $table->index('hold_expires_at');
        });

        // One-time grace: live bookings with money down are guaranteed;
        // the rest become 24h holds so phantom occupancy drains away.
        $graceUntil = now()->addDay();

        DB::table('reservations')
            ->whereIn('status', ['pending', 'confirmed', 'reserved'])
            ->where('amount_paid', '>', 0)
            ->update(['guarantee_status' => 'guaranteed']);

        DB::table('reservations')
            ->whereIn('status', ['pending', 'confirmed', 'reserved'])
            ->where('amount_paid', '<=', 0)
            ->update(['guarantee_status' => 'hold', 'hold_expires_at' => $graceUntil]);

        DB::table('reservations')
            ->where('status', 'checked_in')
            ->update(['guarantee_status' => 'guaranteed']);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('reservations', 'guarantee_status')) {
            return;
        }

        Schema::table('reservations', function (Blueprint $table) {
            if (Schema::hasIndex('reservations', 'reservations_branch_id_guarantee_status_index')) {
                $table->dropIndex('reservations_branch_id_guarantee_status_index');
            }
            if (Schema::hasIndex('reservations', 'reservations_hold_expires_at_index')) {
                $table->dropIndex('reservations_hold_expires_at_index');
            }
            $table->dropColumn([
                'guarantee_status',
                'deposit_due_minor',
                'deposit_paid_minor',
                'cancel_deadline_at',
                'hold_expires_at',
            ]);
        });
    }
};
