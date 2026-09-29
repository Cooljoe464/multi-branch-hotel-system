<?php

namespace App\Services;

use App\Events\HkSchedulePublished;
use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\HkSchedule;
use App\Models\HousekeepingTask;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Support\BranchTime;
use Illuminate\Support\Facades\DB;

/**
 * Nightly housekeeping planner. Rooms are ranked (VIP arrival >
 * checkout turnover > stayover), clustered by floor, and dealt to
 * attendants under a daily credit cap. Re-runs regenerate everything
 * except manually pinned rows; publishing materializes tasks
 * idempotently — a second publish creates nothing new.
 *
 * @phpstan-type NeedRow array{room_id: int, kind: string, vip: bool, floor: string}
 * @phpstan-type AssignmentRow array{attendant_id: int|null, kind: string, credits: int, pinned: bool}
 */
class HkSchedulerService
{
    public const CREDITS = ['checkout_clean' => 10, 'stayover' => 6, 'inspection' => 4];

    public const DAILY_CAP = 100;

    /**
     * @return list<NeedRow>
     */
    private function roomsNeedingService(Branch $branch, string $workDate): array
    {
        /** @var list<NeedRow> $need */
        $need = [];

        $stays = Reservation::forBranch($branch->id)
            ->whereIn('status', ['checked_in', 'confirmed', 'reserved'])
            ->where(function ($q) use ($workDate) {
                $q->where('check_out_date', $workDate)->orWhere('check_in_date', $workDate)
                    ->orWhere(function ($qq) use ($workDate) {
                        $qq->where('check_in_date', '<', $workDate)->where('check_out_date', '>', $workDate);
                    });
            })
            ->with(['room', 'guest'])
            ->get();

        foreach ($stays as $stay) {
            if ($stay->room_id === null) {
                continue;
            }

            $vip = $stay->guest !== null && $stay->guest->vip_status !== 'none';

            if ($stay->check_out_date->toDateString() === $workDate) {
                $need[$stay->room_id] = $this->row($stay->room_id, 'checkout_clean', $vip);
            } elseif ($stay->check_in_date->toDateString() === $workDate) {
                $need[$stay->room_id] ??= $this->row($stay->room_id, 'inspection', $vip);
            } else {
                $need[$stay->room_id] ??= $this->row($stay->room_id, 'stayover', $vip);
            }
        }

        $dirty = Room::forBranch($branch->id)
            ->where('is_active', true)
            ->where('status', 'dirty')
            ->get(['id', 'floor']);

        foreach ($dirty as $room) {
            $need[$room->id] ??= $this->row($room->id, 'checkout_clean', false, is_string($room->floor) ? $room->floor : '');
        }

        return $need;
    }

    /**
     * @return NeedRow
     */
    private function row(int $roomId, string $kind, bool $vip, string $floor = ''): array
    {
        if ($floor === '') {
            $stored = Room::where('id', $roomId)->value('floor');
            $floor = is_string($stored) ? $stored : '';
        }

        return ['room_id' => $roomId, 'kind' => $kind, 'vip' => $vip, 'floor' => $floor];
    }

    /**
     * @return list<int> attendant user ids
     */
    private function attendants(Branch $branch): array
    {
        $ids = User::where('branch_id', $branch->id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'Housekeeper'))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        return array_values(array_filter($ids, is_int(...)));
    }

    /**
     * @return array<int, AssignmentRow>
     */
    public function normalizeAssignments(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $roomId => $row) {
            $room = filter_var($roomId, FILTER_VALIDATE_INT);

            if ($room === false || ! is_array($row)) {
                continue;
            }

            $kind = $row['kind'] ?? null;
            $credits = $row['credits'] ?? 0;
            $attendant = $row['attendant_id'] ?? null;

            if (! is_string($kind) || ! is_int($credits)) {
                continue;
            }

            $out[$room] = [
                'attendant_id' => is_int($attendant) ? $attendant : null,
                'kind' => $kind,
                'credits' => $credits,
                'pinned' => ($row['pinned'] ?? false) === true,
            ];
        }

        return $out;
    }

    public function plan(Branch $branch, string $workDate, int $cap = self::DAILY_CAP): HkSchedule
    {
        return DB::transaction(function () use ($branch, $workDate, $cap) {
            $schedule = HkSchedule::firstOrCreate(
                ['branch_id' => $branch->id, 'work_date' => $workDate],
                ['assignments' => [], 'status' => HkSchedule::STATUS_DRAFT],
            );

            $previous = $this->normalizeAssignments($schedule->assignments);
            $need = $this->roomsNeedingService($branch, $workDate);
            $neededIds = array_column($need, 'room_id');

            // Pins survive re-runs; stale and unpinned rows regenerate.
            /** @var array<int, AssignmentRow> $assignments */
            $assignments = [];
            foreach ($previous as $roomId => $row) {
                if ($row['pinned'] && in_array($roomId, $neededIds, true)) {
                    $assignments[$roomId] = $row;
                }
            }

            $rank = ['inspection' => 0, 'checkout_clean' => 1, 'stayover' => 2];
            usort($need, function (array $a, array $b) use ($rank) {
                $pa = ($a['vip'] && $a['kind'] === 'inspection' ? -1 : $rank[$a['kind']] ?? 9);
                $pb = ($b['vip'] && $b['kind'] === 'inspection' ? -1 : $rank[$b['kind']] ?? 9);

                if ($pa !== $pb) {
                    return $pa <=> $pb;
                }

                if ($a['floor'] !== $b['floor']) {
                    return $a['floor'] <=> $b['floor'];
                }

                return $a['room_id'] <=> $b['room_id'];
            });

            $attendants = $this->attendants($branch);
            $loads = [];
            foreach ($attendants as $id) {
                $loads[$id] = 0;
            }

            foreach ($assignments as $row) {
                if ($row['attendant_id'] !== null) {
                    $loads[$row['attendant_id']] = ($loads[$row['attendant_id']] ?? 0) + $row['credits'];
                }
            }

            $lastAttendant = null;
            $lastFloor = null;

            foreach ($need as $item) {
                if (isset($assignments[$item['room_id']])) {
                    continue;
                }

                $credits = self::CREDITS[$item['kind']] ?? 6;
                $chosen = null;

                // Floor affinity first: keep an attendant on one floor
                // while they still fit under the cap.
                if ($lastAttendant !== null && $lastFloor === $item['floor']
                    && ($loads[$lastAttendant] ?? 0) + $credits <= $cap
                ) {
                    $chosen = $lastAttendant;
                } else {
                    foreach ($attendants as $id) {
                        if (($loads[$id] ?? 0) + $credits <= $cap && ($chosen === null || ($loads[$id] ?? 0) < ($loads[$chosen] ?? 0))) {
                            $chosen = $id;
                        }
                    }
                }

                $assignments[$item['room_id']] = [
                    'attendant_id' => $chosen,
                    'kind' => $item['kind'],
                    'credits' => $credits,
                    'pinned' => false,
                ];

                if ($chosen !== null) {
                    $loads[$chosen] = ($loads[$chosen] ?? 0) + $credits;
                    $lastAttendant = $chosen;
                    $lastFloor = $item['floor'];
                }
            }

            $schedule->update(['assignments' => $assignments, 'status' => HkSchedule::STATUS_DRAFT]);

            return $schedule->fresh() ?? $schedule;
        });
    }

    public function publish(HkSchedule $schedule, User $publishedBy): HkSchedule
    {
        if (! $publishedBy->hasRole('Branch GM') && ! $publishedBy->hasRole('Global Admin')) {
            throw new AvailabilityException('HK_PUBLISH', 'Only the Branch GM publishes schedules.');
        }

        return DB::transaction(function () use ($schedule) {
            $locked = HkSchedule::where('id', $schedule->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === HkSchedule::STATUS_PUBLISHED) {
                return $locked;
            }

            $assignments = $this->normalizeAssignments($locked->assignments);
            $today = BranchTime::today(Branch::findOrFail($locked->branch_id));

            foreach ($assignments as $roomId => $row) {
                if ($row['attendant_id'] === null) {
                    continue;
                }

                $exists = HousekeepingTask::forBranch($locked->branch_id)
                    ->where('room_id', $roomId)
                    ->where('kind', $row['kind'])
                    ->open()
                    ->whereDate('created_at', $today)
                    ->exists();

                if ($exists) {
                    continue;
                }

                HousekeepingTask::create([
                    'branch_id' => $locked->branch_id,
                    'room_id' => $roomId,
                    'kind' => $row['kind'],
                    'credits' => $row['credits'],
                    'assignee_id' => $row['attendant_id'],
                    'status' => HousekeepingTask::STATUS_OPEN,
                ]);
            }

            $locked->update(['status' => HkSchedule::STATUS_PUBLISHED]);

            event(new HkSchedulePublished($locked->fresh() ?? $locked));

            return $locked->fresh() ?? $locked;
        });
    }
}
