<?php

namespace App\Services;

use App\Events\HkTaskAssigned;
use App\Events\HkTaskCompleted;
use App\Events\InspectionFailed;
use App\Events\RoomConditionChanged;
use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\Folio;
use App\Models\HousekeepingTask;
use App\Models\MinibarPosting;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomOut;
use App\Models\RoomType;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Housekeeping depth: credit-capped allocation, inspection-gated
 * completion, minibar folio posting, OOO inventory control and room
 * condition tracking. The legacy room status keeps driving ops flows;
 * condition is the housekeeping layer on top.
 */
class HousekeepingService
{
    public const CONDITIONS = ['clean', 'dirty', 'inspected', 'ooo', 'oos'];

    /**
     * Set a room's condition, syncing the legacy status where the
     * mapping is unambiguous (ooo forces out_of_order; a dirty
     * available room stays dirty; cleaning a dirty room frees it).
     */
    public function setCondition(Room $room, string $condition, ?string $reason, ?User $by = null): Room
    {
        if (! in_array($condition, self::CONDITIONS, true)) {
            throw new AvailabilityException('HK_CONDITION', "Unknown room condition {$condition}.");
        }

        return DB::transaction(function () use ($room, $condition, $reason, $by) {
            $locked = Room::where('id', $room->id)->lockForUpdate()->firstOrFail();

            $status = $locked->status;

            if ($condition === 'ooo') {
                $status = 'out_of_order';
            } elseif ($condition === 'clean' && $status === 'dirty') {
                $status = 'available';
            } elseif ($condition === 'dirty' && $status === 'available') {
                $status = 'dirty';
            }

            $locked->update([
                'condition' => $condition,
                'condition_reason' => $reason,
            ]);

            if ($status !== $locked->status) {
                $locked->update(['status' => $status]);
            }

            event(new RoomConditionChanged($locked->fresh() ?? $locked, $by));

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Take a room out of order for a date range: condition flips and
     * each night loses one physical room from sellable inventory.
     */
    public function setOutOfOrder(Room $room, string $from, string $to, ?string $reason, ?User $by = null): RoomOut
    {
        if (Carbon::parse($to)->lessThan(Carbon::parse($from))) {
            throw new AvailabilityException('HK_DATES', 'Out-of-order end must be on or after the start.');
        }

        return DB::transaction(function () use ($room, $from, $to, $reason, $by) {
            $locked = Room::where('id', $room->id)->lockForUpdate()->firstOrFail();
            $roomType = RoomType::findOrFail($locked->room_type_id);
            $branch = Branch::findOrFail($locked->branch_id);

            $out = RoomOut::create([
                'branch_id' => $locked->branch_id,
                'room_id' => $locked->id,
                'from_date' => $from,
                'to_date' => $to,
                'reason' => $reason,
            ]);

            $availability = new AvailabilityService;

            foreach ($availability->nights($from, Carbon::parse($to)->addDay()->toDateString()) as $date) {
                $availability->adjustTotalRooms($branch, $roomType, $date, -1);
            }

            $this->setCondition($locked, 'ooo', $reason, $by);

            return $out->fresh() ?? $out;
        });
    }

    /**
     * End an OOO span early (or on schedule): inventory totals restore
     * capped at the physical room count, condition returns to clean.
     */
    public function clearOutOfOrder(RoomOut $out, ?User $by = null): Room
    {
        return DB::transaction(function () use ($out, $by) {
            $locked = RoomOut::where('id', $out->id)->lockForUpdate()->firstOrFail();
            $room = Room::where('id', $locked->room_id)->lockForUpdate()->firstOrFail();
            $roomType = RoomType::findOrFail($room->room_type_id);
            $branch = Branch::findOrFail($room->branch_id);

            $physical = Room::where('branch_id', $branch->id)
                ->where('room_type_id', $roomType->id)
                ->where('is_active', true)
                ->count();

            $availability = new AvailabilityService;

            foreach ($availability->nights($locked->from_date->toDateString(), $locked->to_date->copy()->addDay()->toDateString()) as $date) {
                $availability->adjustTotalRooms($branch, $roomType, $date, 1, $physical);
            }

            $locked->delete();

            return $this->setCondition($room->fresh() ?? $room, 'clean', null, $by);
        });
    }

    /**
     * Assign a task, enforcing the attendant's credit cap. The
     * assignee row lock serializes concurrent assignments.
     */
    public function assign(HousekeepingTask $task, User $assignee, ?User $by = null): HousekeepingTask
    {
        if ($assignee->branch_id !== $task->branch_id) {
            throw new AvailabilityException('HK_BRANCH', 'Attendants can only take tasks at their own property.');
        }

        return DB::transaction(function () use ($task, $assignee, $by) {
            User::where('id', $assignee->id)->lockForUpdate()->firstOrFail();
            $locked = HousekeepingTask::where('id', $task->id)->lockForUpdate()->firstOrFail();

            $load = (int) HousekeepingTask::where('assignee_id', $assignee->id)
                ->open()
                ->where('id', '!=', $locked->id)
                ->sum('credits');

            if ($load + $locked->credits > $this->creditCap($locked->branch_id)) {
                throw new AvailabilityException('HK_OVER_ALLOCATED', "Attendant is at {$load} credits; this task needs {$locked->credits}.");
            }

            $locked->update(['assignee_id' => $assignee->id]);

            if ($locked->status === HousekeepingTask::STATUS_OPEN) {
                $locked->update(['status' => HousekeepingTask::STATUS_IN_PROGRESS]);
            }

            event(new HkTaskAssigned($locked->fresh() ?? $locked, $by));

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Greedy auto-allocation of open unassigned tasks to the least
     * loaded eligible attendants (Housekeeper role, same branch).
     *
     * @return array{assigned: int, skipped: int}
     */
    public function autoAllocate(Branch $branch): array
    {
        $attendants = User::where('branch_id', $branch->id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'Housekeeper'))
            ->orderBy('id')
            ->get();

        if ($attendants->isEmpty()) {
            return ['assigned' => 0, 'skipped' => 0];
        }

        $assigned = 0;
        $skipped = 0;

        $tasks = HousekeepingTask::forBranch($branch->id)
            ->where('status', HousekeepingTask::STATUS_OPEN)
            ->whereNull('assignee_id')
            ->orderBy('id')
            ->get();

        foreach ($tasks as $task) {
            $candidate = null;
            $lowest = null;

            foreach ($attendants as $attendant) {
                $load = (int) HousekeepingTask::where('assignee_id', $attendant->id)->open()->sum('credits');

                if ($load + $task->credits <= $this->creditCap($branch->id) && ($lowest === null || $load < $lowest)) {
                    $lowest = $load;
                    $candidate = $attendant;
                }
            }

            if ($candidate) {
                try {
                    $this->assign($task, $candidate);
                    $assigned++;
                } catch (AvailabilityException) {
                    $skipped++;
                }
            } else {
                $skipped++;
            }
        }

        return ['assigned' => $assigned, 'skipped' => $skipped];
    }

    /**
     * Complete a task. Inspectable kinds need a score; below-threshold
     * scores fail inspection and spawn a fresh task. Idempotent.
     *
     * @param  list<UploadedFile>  $photos
     */
    public function complete(HousekeepingTask $task, User $by, ?int $score = null, array $photos = []): HousekeepingTask
    {
        return DB::transaction(function () use ($task, $by, $score, $photos) {
            $locked = HousekeepingTask::where('id', $task->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === HousekeepingTask::STATUS_DONE) {
                return $locked;
            }

            if ($locked->inspectable() && $score === null) {
                throw new AvailabilityException('INSPECTION_REQUIRED', 'Checkout cleans close only with an inspection score.');
            }

            foreach ($photos as $photo) {
                $this->storePhoto($locked, $photo);
            }

            $threshold = $this->inspectionThreshold($locked->branch_id);

            if ($score !== null && $score < $threshold) {
                $locked->update([
                    'status' => HousekeepingTask::STATUS_FAILED_INSPECTION,
                    'inspection_score' => $score,
                ]);

                $retry = HousekeepingTask::create([
                    'branch_id' => $locked->branch_id,
                    'room_id' => $locked->room_id,
                    'kind' => $locked->kind,
                    'credits' => $locked->credits,
                    'status' => HousekeepingTask::STATUS_OPEN,
                ]);

                event(new InspectionFailed($locked->fresh() ?? $locked, $retry));

                return $locked->fresh() ?? $locked;
            }

            $locked->update([
                'status' => HousekeepingTask::STATUS_DONE,
                'inspection_score' => $score ?? $locked->inspection_score,
            ]);

            if ($locked->kind === HousekeepingTask::KIND_CHECKOUT_CLEAN) {
                $room = Room::where('id', $locked->room_id)->lockForUpdate()->first();

                if ($room) {
                    $this->setCondition($room, 'inspected', null, $by);
                }
            }

            event(new HkTaskCompleted($locked->fresh() ?? $locked, $by));

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Post minibar consumption once to the occupying reservation's
     * folio. Replays under the same key return the original charge.
     *
     * @param  list<array<string, mixed>>  $items  {name, qty, unit_price_minor}.
     */
    public function postMinibar(Room $room, array $items, User $postedBy, string $idempotencyKey): Transaction
    {
        return DB::transaction(function () use ($room, $items, $postedBy, $idempotencyKey) {
            $locked = Room::where('id', $room->id)->lockForUpdate()->firstOrFail();

            $posting = MinibarPosting::firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'branch_id' => $locked->branch_id,
                    'room_id' => $locked->id,
                    'items' => [],
                    'total_minor' => 0,
                ],
            );

            $existing = Transaction::where('is_voided', false)
                ->where('metadata->minibar_posting_id', $posting->id)
                ->first();

            if ($existing) {
                return $existing;
            }

            $total = 0;
            $lines = [];
            foreach ($items as $item) {
                $qty = $item['qty'] ?? 0;
                $price = $item['unit_price_minor'] ?? 0;
                $qty = is_int($qty) ? $qty : 0;
                $price = is_int($price) ? $price : 0;
                $name = $item['name'] ?? 'Minibar';
                $name = is_string($name) ? $name : 'Minibar';
                $lines[] = ['name' => $name, 'qty' => $qty, 'unit_price_minor' => $price];
                $total += $qty * $price;
            }

            if ($total <= 0) {
                throw new AvailabilityException('MINIBAR_EMPTY', 'Minibar postings need at least one priced item.');
            }

            $today = Carbon::today()->toDateString();
            $reservation = Reservation::where('branch_id', $locked->branch_id)
                ->where('room_id', $locked->id)
                ->where('status', 'checked_in')
                ->where('check_in_date', '<=', $today)
                ->where('check_out_date', '>', $today)
                ->first();

            if (! $reservation) {
                throw new AvailabilityException('MINIBAR_NO_FOLIO', 'Minibar needs an occupying checked-in reservation.');
            }

            $folio = Folio::where('reservation_id', $reservation->id)->first()
                ?? (new FolioService)->createFolio($locked->branch_id, $reservation->id, null, "Guest Folio: {$reservation->guest_name}");

            $names = implode(', ', array_map(fn ($line) => "{$line['qty']}x {$line['name']}", $lines));

            $charge = (new FolioService)->postDebit(
                $folio,
                'minibar',
                "Minibar ({$locked->number}): {$names}",
                $total,
                $postedBy->id,
                windowCode: 'incidentals',
                metadata: ['minibar_posting_id' => $posting->id],
            );

            $posting->update([
                'folio_id' => $folio->id,
                'reservation_id' => $reservation->id,
                'items' => $lines,
                'total_minor' => $total,
            ]);

            return $charge;
        });
    }

    private function creditCap(int $branchId): int
    {
        $branch = Branch::find($branchId);
        $settings = $branch?->settings;

        if (is_array($settings) && isset($settings['hk_credit_cap']) && is_int($settings['hk_credit_cap'])) {
            return $settings['hk_credit_cap'];
        }

        return 100;
    }

    private function inspectionThreshold(int $branchId): int
    {
        $branch = Branch::find($branchId);
        $settings = $branch?->settings;

        if (is_array($settings) && isset($settings['hk_inspection_threshold']) && is_int($settings['hk_inspection_threshold'])) {
            return $settings['hk_inspection_threshold'];
        }

        return 70;
    }

    private function storePhoto(HousekeepingTask $task, UploadedFile $photo): void
    {
        $path = $photo->store("inspections/{$task->id}", 'r2');

        if (! is_string($path)) {
            throw new AvailabilityException('HK_PHOTO', 'Inspection photo upload failed.');
        }

        $paths = $task->photo_paths ?? [];
        $paths[] = $path;

        $task->update(['photo_paths' => $paths]);
    }
}
