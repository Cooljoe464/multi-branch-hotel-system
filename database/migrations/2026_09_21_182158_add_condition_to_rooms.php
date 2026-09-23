<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('condition', 16)->default('dirty');
            $table->string('condition_reason', 255)->nullable();
        });

        // Backfill from legacy status; occupied rooms get a checkout
        // clean task so the new queue starts with the real workload.
        $map = [
            'available' => 'clean',
            'reserved' => 'clean',
            'occupied' => 'dirty',
            'dirty' => 'dirty',
            'out_of_order' => 'ooo',
        ];

        foreach ($map as $status => $condition) {
            DB::table('rooms')->where('status', $status)->update(['condition' => $condition]);
        }

        $occupied = DB::table('rooms')->where('status', 'occupied')->get(['id', 'branch_id']);

        foreach ($occupied as $room) {
            DB::table('housekeeping_tasks')->insert([
                'branch_id' => $room->branch_id,
                'room_id' => $room->id,
                'kind' => 'checkout_clean',
                'credits' => 15,
                'status' => 'open',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['condition', 'condition_reason']);
        });
    }
};
