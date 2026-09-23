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
            $table->foreignId('group_block_id')->nullable()->constrained()->nullOnDelete();
        });

        // Backfill: one block per legacy group_id; pickup = linked count.
        $groups = DB::table('reservations')
            ->where('is_group_booking', true)
            ->whereNotNull('group_id')
            ->select('branch_id', 'group_id')
            ->selectRaw('MIN(check_in_date) as first_in, MAX(check_out_date) as last_out, COUNT(*) as picked')
            ->groupBy('branch_id', 'group_id')
            ->get();

        foreach ($groups as $group) {
            $code = substr((string) $group->group_id, 0, 32);

            $blockId = DB::table('group_blocks')
                ->where('branch_id', $group->branch_id)
                ->where('code', $code)
                ->value('id');

            if (! $blockId) {
                $blockId = DB::table('group_blocks')->insertGetId([
                    'branch_id' => $group->branch_id,
                    'name' => "Legacy group {$group->group_id}",
                    'code' => $code,
                    'cutoff_date' => $group->first_in,
                    'attrition_pct' => 0,
                    'status' => 'definite',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('reservations')
                ->where('branch_id', $group->branch_id)
                ->where('group_id', $group->group_id)
                ->update(['group_block_id' => $blockId]);
        }
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_block_id');
        });
    }
};
