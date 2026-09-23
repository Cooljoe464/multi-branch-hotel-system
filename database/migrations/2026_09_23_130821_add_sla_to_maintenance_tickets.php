<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_tickets', function (Blueprint $table) {
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('sla_due_at')->nullable();
            $table->timestampTz('responded_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
        });

        // Backfill: one unclassified asset per distinct ticket subject,
        // linked by room; SLA clocks start now (no retroactive breach).
        $subjects = DB::table('maintenance_tickets')
            ->select('branch_id', 'title', 'room_id')
            ->selectRaw('MIN(id) as sample_id')
            ->groupBy('branch_id', 'title', 'room_id')
            ->get();

        foreach ($subjects as $subject) {
            $rawTitle = $subject->title ?? null;
            $fullTitle = is_scalar($rawTitle) ? (string) $rawTitle : '';
            $name = substr($fullTitle, 0, 128);

            $assetId = DB::table('assets')->insertGetId([
                'branch_id' => $subject->branch_id,
                'name' => $name,
                'category' => 'other',
                'room_id' => $subject->room_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('maintenance_tickets')
                ->where('branch_id', $subject->branch_id)
                ->where('title', $fullTitle)
                ->where('room_id', $subject->room_id)
                ->update(['asset_id' => $assetId]);
        }

        // whereNull(room_id) groups collapse in the groupBy above on some
        // drivers; link the rest by branch + title.
        $orphans = DB::table('maintenance_tickets')->whereNull('asset_id')->get(['id', 'branch_id', 'title']);

        foreach ($orphans as $ticket) {
            $rawOrphanTitle = $ticket->title ?? null;
            $orphanName = is_scalar($rawOrphanTitle) ? substr((string) $rawOrphanTitle, 0, 128) : '';

            $assetId = DB::table('assets')
                ->where('branch_id', $ticket->branch_id)
                ->where('name', $orphanName)
                ->value('id');

            if ($assetId) {
                DB::table('maintenance_tickets')->where('id', $ticket->id)->update(['asset_id' => $assetId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('maintenance_tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('asset_id');
            $table->dropColumn(['sla_due_at', 'responded_at', 'resolved_at']);
        });
    }
};
