<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\LaundryBatch;
use App\Models\LinenDiscard;
use App\Models\LinenItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Linen inventory tracking: batch dispatch/return, discard logging,
 * par level monitoring, and inventory summaries.
 */
class LinenTrackingService
{
    /**
     * Dispatch a laundry batch, moving items from dirty to in-laundry.
     *
     * @param  array<int, int>  $itemCounts  [linen_item_id => quantity]
     */
    public function dispatchBatch(Branch $branch, User $dispatcher, array $itemCounts, string $vendorType = 'internal', ?string $vendorName = null): LaundryBatch
    {
        return DB::transaction(function () use ($branch, $dispatcher, $itemCounts, $vendorType, $vendorName) {
            foreach ($itemCounts as $itemId => $quantity) {
                $item = LinenItem::where('id', $itemId)
                    ->where('branch_id', $branch->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($item->current_dirty < $quantity) {
                    throw new LogicException("Insufficient dirty count for \"{$item->name}\": have {$item->current_dirty}, need {$quantity}.");
                }

                $item->decrement('current_dirty', $quantity);
                $item->increment('current_in_laundry', $quantity);
            }

            $todayCount = LaundryBatch::where('branch_id', $branch->id)
                ->whereDate('dispatched_at', now()->toDateString())
                ->count();

            $batchNumber = 'LB-'.now()->format('Ymd').'-'.str_pad($todayCount + 1, 3, '0', STR_PAD_LEFT);

            return LaundryBatch::create([
                'branch_id' => $branch->id,
                'batch_number' => $batchNumber,
                'vendor_type' => $vendorType,
                'vendor_name' => $vendorName,
                'status' => LaundryBatch::STATUS_SENT,
                'item_counts' => $itemCounts,
                'dispatched_at' => now(),
                'dispatched_by' => $dispatcher->id,
            ]);
        });
    }

    /**
     * Receive a returned laundry batch, moving items from in-laundry to clean.
     *
     * @param  array<int, int>|null  $actualCounts  Actual returned counts, or null to use original
     */
    public function receiveBatch(LaundryBatch $batch, User $receiver, ?array $actualCounts = null): LaundryBatch
    {
        return DB::transaction(function () use ($batch, $receiver, $actualCounts) {
            $counts = $actualCounts ?? $batch->item_counts;

            foreach ($counts as $itemId => $quantity) {
                $item = LinenItem::where('id', $itemId)->lockForUpdate()->firstOrFail();
                $item->decrement('current_in_laundry', $quantity);
                $item->increment('current_clean', $quantity);
            }

            $batch->update([
                'status' => LaundryBatch::STATUS_RETURNED,
                'returned_at' => now(),
                'received_by' => $receiver->id,
            ]);

            return $batch->fresh() ?? $batch;
        });
    }

    /**
     * Record linen discard (torn, stained, etc.).
     */
    public function recordDiscard(Branch $branch, int $linenItemId, int $quantity, string $reason, User $approvedBy, string $fromPool = 'dirty', ?string $notes = null): LinenDiscard
    {
        return DB::transaction(function () use ($branch, $linenItemId, $quantity, $reason, $approvedBy, $fromPool, $notes) {
            $item = LinenItem::where('id', $linenItemId)
                ->where('branch_id', $branch->id)
                ->lockForUpdate()
                ->firstOrFail();

            $poolField = $fromPool === 'clean' ? 'current_clean' : 'current_dirty';
            $item->decrement($poolField, $quantity);

            return LinenDiscard::create([
                'branch_id' => $branch->id,
                'linen_item_id' => $linenItemId,
                'quantity' => $quantity,
                'reason' => $reason,
                'unit_cost_minor' => $item->unit_cost_minor,
                'total_loss_minor' => $quantity * $item->unit_cost_minor,
                'approved_by' => $approvedBy->id,
                'notes' => $notes,
            ]);
        });
    }

    /**
     * Get linen items below par level.
     *
     * @return Collection<int, LinenItem>
     */
    public function parLevelAlerts(Branch $branch): Collection
    {
        return LinenItem::forBranch($branch->id)->belowPar()->get();
    }

    /**
     * Generate inventory summary per category.
     *
     * @return array<string, array{total_clean: int, total_dirty: int, total_in_laundry: int, total_par: int, items_below_par: int}>
     */
    public function inventorySummary(Branch $branch): array
    {
        $items = LinenItem::forBranch($branch->id)->get();

        $summary = [];

        foreach ($items->groupBy('category') as $category => $group) {
            $summary[$category] = [
                'total_clean' => $group->sum('current_clean'),
                'total_dirty' => $group->sum('current_dirty'),
                'total_in_laundry' => $group->sum('current_in_laundry'),
                'total_par' => $group->sum('par_level'),
                'items_below_par' => $group->filter(fn (LinenItem $i) => ($i->current_clean + $i->current_in_laundry) < $i->par_level)->count(),
            ];
        }

        return $summary;
    }
}
