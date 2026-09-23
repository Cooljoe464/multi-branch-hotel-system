<?php

namespace App\Services;

use App\Events\BeoUpdated;
use App\Events\BlockCutoffReleased;
use App\Events\BlockPickupChanged;
use App\Exceptions\AvailabilityException;
use App\Models\BanquetEventOrder;
use App\Models\Branch;
use App\Models\Folio;
use App\Models\GroupBlock;
use App\Models\GroupBlockNight;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Group room blocks: holds reduce sellable via inventory blocked
 * counts; pickup atomically converts held nights into reservations
 * (blocked→sold swap, sellable unchanged); past-cutoff leftovers
 * auto-release. BEO spend posts once to the master folio's banquet
 * window. All money in integer minor units.
 */
class GroupBlockService
{
    /**
     * Create a block with held nights.
     *
     * @param  array<string, mixed>  $attributes  name, code, cutoff_date, attrition_pct, status.
     * @param  list<array<string, mixed>>  $holds  Raw hold rows; each is normalized defensively.
     */
    public function create(Branch $branch, array $attributes, array $holds): GroupBlock
    {
        return DB::transaction(function () use ($branch, $attributes, $holds) {
            $name = $attributes['name'] ?? 'Unnamed block';
            $code = $attributes['code'] ?? null;
            $cutoff = $attributes['cutoff_date'] ?? null;
            $attrition = $attributes['attrition_pct'] ?? 0;
            $status = $attributes['status'] ?? GroupBlock::STATUS_TENTATIVE;

            $block = GroupBlock::create([
                'branch_id' => $branch->id,
                'name' => is_string($name) ? $name : 'Unnamed block',
                'code' => is_string($code) && $code !== '' ? $code : 'BLK-'.strtoupper(Carbon::now()->format('ymd-His')),
                'cutoff_date' => is_string($cutoff) ? $cutoff : Carbon::today()->toDateString(),
                'attrition_pct' => is_int($attrition) ? $attrition : 0,
                'status' => is_string($status) ? $status : GroupBlock::STATUS_TENTATIVE,
            ]);

            $availability = new AvailabilityService;

            foreach ($holds as $hold) {
                $roomTypeId = $hold['room_type_id'] ?? 0;
                $from = $hold['from'] ?? '';
                $to = $hold['to'] ?? '';
                $blocked = $hold['blocked'] ?? 0;

                $roomType = is_int($roomTypeId) ? RoomType::find($roomTypeId) : null;

                if (! $roomType || $roomType->branch_id !== $branch->id) {
                    throw new AvailabilityException('BLOCK_ROOM_TYPE', 'Blocked room types must belong to this property.');
                }

                if (! is_string($from) || ! is_string($to)) {
                    throw new AvailabilityException('BLOCK_DATES', 'Block holds need valid from/to dates.');
                }

                $count = max(0, is_int($blocked) ? $blocked : 0);

                foreach ($availability->nights($from, $to) as $date) {
                    $night = GroupBlockNight::firstOrCreate(
                        ['group_block_id' => $block->id, 'room_type_id' => $roomType->id, 'stay_date' => $date],
                        ['blocked' => 0, 'picked_up' => 0],
                    );

                    if ($count > 0) {
                        $night->increment('blocked', $count);
                    }

                    $availability->addBlock($branch, $roomType, $date, $count);
                }
            }

            return $block->fresh() ?? $block;
        });
    }

    /**
     * Convert held nights into a real reservation. Concurrent pickups
     * for the last held night serialize on the block-night row: one
     * wins, the rest get BLOCK_SOLD_OUT.
     *
     * @param  array<string, mixed>  $attributes  Reservation attributes.
     */
    public function pickup(
        GroupBlock $block,
        RoomType $roomType,
        string $checkIn,
        string $checkOut,
        array $attributes,
        ?int $roomId = null,
        ?string $idempotencyKey = null,
        ?User $bookedBy = null,
    ): Reservation {
        $blockBranchId = $block->branch_id;

        if ($roomType->branch_id !== $blockBranchId) {
            throw new AvailabilityException('BLOCK_ROOM_TYPE', 'The room type does not belong to the block property.');
        }

        return DB::transaction(function () use ($block, $roomType, $checkIn, $checkOut, $attributes, $roomId, $idempotencyKey, $blockBranchId) {
            // Fast path for replays: same discipline as reserve() itself
            // (unlocked pre-check + locked re-check inside reserve).
            if ($idempotencyKey !== null) {
                $replay = Reservation::where('branch_id', $blockBranchId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($replay) {
                    return $replay;
                }
            }

            $locked = GroupBlock::where('id', $block->id)->lockForUpdate()->firstOrFail();

            if (! $locked->releasable()) {
                throw new AvailabilityException('BLOCK_CLOSED', "Block {$locked->code} is {$locked->status}.");
            }

            $branch = Branch::findOrFail($blockBranchId);
            $availability = new AvailabilityService;
            $nights = $availability->nights($checkIn, $checkOut);
            $exhausted = [];

            foreach ($nights as $date) {
                $row = GroupBlockNight::where('group_block_id', $locked->id)
                    ->where('room_type_id', $roomType->id)
                    ->where('stay_date', $date)
                    ->lockForUpdate()
                    ->first();

                if (! $row || $row->remaining() < 1) {
                    $exhausted[] = $date;
                }
            }

            if ($exhausted !== []) {
                throw new AvailabilityException('BLOCK_SOLD_OUT', 'No held nights left for the requested dates.', $exhausted);
            }

            foreach ($nights as $date) {
                GroupBlockNight::where('group_block_id', $locked->id)
                    ->where('room_type_id', $roomType->id)
                    ->where('stay_date', $date)
                    ->increment('picked_up');

                $availability->addBlock($branch, $roomType, $date, -1);
            }

            $reservation = $availability->reserve(
                branch: $branch,
                roomType: $roomType,
                checkIn: $checkIn,
                checkOut: $checkOut,
                attributes: array_merge($attributes, [
                    'group_block_id' => $locked->id,
                    'is_group_booking' => true,
                ]),
                roomId: $roomId,
                idempotencyKey: $idempotencyKey,
            );

            event(new BlockPickupChanged($locked->fresh() ?? $locked, $reservation));

            return $reservation;
        }, 3);
    }

    /**
     * Release all unpicked held nights. Re-runs are no-ops (nothing
     * left to release). Returns the released night count.
     */
    public function release(GroupBlock $block, string $toStatus, string $reason): int
    {
        return DB::transaction(function () use ($block, $toStatus, $reason) {
            $locked = GroupBlock::where('id', $block->id)->lockForUpdate()->firstOrFail();

            if (! $locked->releasable()) {
                return 0;
            }

            $branch = Branch::findOrFail($locked->branch_id);
            $availability = new AvailabilityService;
            $released = 0;

            $rows = GroupBlockNight::where('group_block_id', $locked->id)
                ->lockForUpdate()
                ->get();

            foreach ($rows as $row) {
                $left = $row->remaining();

                if ($left > 0) {
                    $availability->addBlock($branch, $row->roomType, $row->stay_date->toDateString(), -$left);
                    $row->update(['blocked' => $row->picked_up]);
                    $released += $left;
                }
            }

            $locked->update(['status' => $toStatus]);

            event(new BlockCutoffReleased($locked->fresh() ?? $locked, $reason, $released));

            return $released;
        });
    }

    /**
     * Past-cutoff auto-release (CutoffJob). Future cutoffs are untouched.
     */
    public function cutoffRelease(GroupBlock $block): int
    {
        if (Carbon::today()->lessThanOrEqualTo($block->cutoff_date)) {
            return 0;
        }

        return $this->release($block, GroupBlock::STATUS_COMPLETED, 'cutoff');
    }

    /**
     * Post the agreed BEO spend once to the master folio's banquet
     * window. Re-runs return the original line.
     */
    public function postBeo(BanquetEventOrder $beo, ?User $postedBy = null): ?Transaction
    {
        return DB::transaction(function () use ($beo, $postedBy) {
            $locked = BanquetEventOrder::where('id', $beo->id)->lockForUpdate()->firstOrFail();

            $folioService = new FolioService;
            $branch = Branch::findOrFail($locked->branch_id);

            // Re-runs return the original line; the folio is only
            // created when there is something to post.
            if ($locked->status === BanquetEventOrder::STATUS_POSTED && $locked->group_block_id !== null) {
                $masterId = GroupBlock::where('id', $locked->group_block_id)->value('master_folio_id');

                if (is_int($masterId)) {
                    $line = Transaction::where('folio_id', $masterId)
                        ->where('category', 'banquet')
                        ->where('is_voided', false)
                        ->where('metadata->beo_id', $locked->id)
                        ->first();

                    if ($line) {
                        return $line;
                    }
                }
            }

            $folio = $locked->group_block_id !== null
                ? $this->masterFolio($locked->group_block_id, $branch)
                : $folioService->createFolio($branch->id, null, null, "Banquet: {$locked->id}");

            $existing = Transaction::where('folio_id', $folio->id)
                ->where('category', 'banquet')
                ->where('is_voided', false)
                ->where('metadata->beo_id', $locked->id)
                ->first();

            if ($existing) {
                $locked->update(['status' => BanquetEventOrder::STATUS_POSTED]);

                return $existing;
            }

            $space = $locked->functionSpace;
            $charge = $folioService->postDebit(
                $folio,
                'banquet',
                "BEO #{$locked->id}: {$space->name} - {$locked->event_date->toDateString()}",
                $locked->agreed_total_minor,
                $postedBy?->id,
                referenceType: BanquetEventOrder::class,
                referenceId: $locked->id,
                windowCode: 'banquet',
                metadata: ['beo_id' => $locked->id],
            );

            $locked->update(['status' => BanquetEventOrder::STATUS_POSTED]);

            event(new BeoUpdated($locked->fresh() ?? $locked));

            return $charge;
        });
    }

    private function masterFolio(int $blockId, Branch $branch): Folio
    {
        $block = GroupBlock::where('id', $blockId)->lockForUpdate()->firstOrFail();

        if ($block->master_folio_id !== null) {
            $folio = Folio::find($block->master_folio_id);

            if ($folio) {
                return $folio;
            }
        }

        $folio = (new FolioService)->createFolio($branch->id, null, null, "Group master: {$block->code}");
        $block->update(['master_folio_id' => $folio->id]);

        return $folio;
    }
}
