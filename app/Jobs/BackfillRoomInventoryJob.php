<?php

namespace App\Jobs;

use App\Models\Branch;
use App\Models\Reservation;
use App\Models\ReservationNight;
use App\Models\Room;
use App\Models\RoomTypeInventory;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One-shot build of the per-date inventory from physical rooms plus
 * active reservations exploded per night. Idempotent: every write is
 * updateOrCreate keyed on the unique pairs, so re-runs change zero rows.
 */
class BackfillRoomInventoryJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    /**
     * @return array{inventory_rows: int, nights: int}
     */
    public function handle(): array
    {
        $stats = ['inventory_rows' => 0, 'nights' => 0];

        Branch::query()->chunkById(100, function ($branches) use (&$stats) {
            foreach ($branches as $branch) {
                $stats['inventory_rows'] += $this->backfillTypeRows($branch);

                Reservation::forBranch($branch->id)
                    ->active()
                    ->orderBy('id')
                    ->chunkById(200, function ($reservations) use (&$stats) {
                        foreach ($reservations as $reservation) {
                            $stats['nights'] += $this->layNights($reservation);
                        }
                    });
            }
        });

        $this->recountSold();

        return $stats;
    }

    /**
     * Bulk-insert one row per (room type, date) for the working window.
     * updateOrCreate per night is ~11k queries; a single chunked upsert
     * replaces it. Returns the number of newly created rows.
     */
    private function backfillTypeRows(Branch $branch): int
    {
        $created = 0;
        $now = now()->toDateTimeString();

        foreach ($branch->roomTypes()->where('is_active', true)->get() as $roomType) {
            $total = Room::where('branch_id', $branch->id)
                ->where('room_type_id', $roomType->id)
                ->where('is_active', true)
                ->count();

            $dates = $this->dateRange();

            $existing = [];
            foreach (RoomTypeInventory::forRoomType($roomType->id)->whereIn('stay_date', $dates)->pluck('stay_date') as $existingDate) {
                if ($existingDate instanceof CarbonInterface) {
                    $existing[] = $existingDate->toDateString();
                } elseif (is_string($existingDate)) {
                    $existing[] = Carbon::parse($existingDate)->toDateString();
                }
            }

            $missing = array_values(array_diff($dates, $existing));
            $created += count($missing);

            foreach (array_chunk($missing, 500) as $chunk) {
                RoomTypeInventory::upsert(
                    array_map(fn ($date) => [
                        'branch_id' => $branch->id,
                        'room_type_id' => $roomType->id,
                        'stay_date' => $date,
                        'total_rooms' => $total,
                        'sold' => 0,
                        'blocked' => 0,
                        'overbooking_limit' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $chunk),
                    ['room_type_id', 'stay_date'],
                    ['total_rooms', 'updated_at'],
                );
            }
        }

        return $created;
    }

    private function layNights(Reservation $reservation): int
    {
        $laid = 0;
        $day = Carbon::parse($reservation->check_in_date)->startOfDay();
        $end = Carbon::parse($reservation->check_out_date)->startOfDay();

        while ($day->lessThan($end)) {
            $night = ReservationNight::firstOrCreate(
                ['reservation_id' => $reservation->id, 'stay_date' => $day->toDateString()],
                ['room_id' => $reservation->room_id]
            );

            if ($night->wasRecentlyCreated) {
                $laid++;
            }

            // Reassigned (not mutated in place): now() is CarbonImmutable
            // app-wide (see AppServiceProvider), so bare addDay() loops forever.
            $day = $day->addDay();
        }

        return $laid;
    }

    /**
     * Recompute sold from nights so the ledger matches physical holds even
     * if an older code path wrote inventory directly.
     */
    private function recountSold(): void
    {
        $rows = DB::table('reservation_nights')
            ->join('reservations', 'reservations.id', '=', 'reservation_nights.reservation_id')
            ->whereIn('reservations.status', ['pending', 'confirmed', 'reserved', 'checked_in'])
            ->whereNull('reservations.deleted_at')
            ->select('reservations.room_type_id', 'reservation_nights.stay_date', DB::raw('count(*) as sold'))
            ->groupBy('reservations.room_type_id', 'reservation_nights.stay_date')
            ->get();

        foreach ($rows as $row) {
            if (! is_int($row->room_type_id) || ! is_numeric($row->sold)) {
                continue;
            }

            RoomTypeInventory::forRoomType($row->room_type_id)
                ->where('stay_date', $row->stay_date)
                ->update(['sold' => (int) $row->sold]);
        }
    }

    /**
     * Working window: 90 days back (late charges, audits) + 365 forward.
     *
     * @return list<string>
     */
    private function dateRange(): array
    {
        $dates = [];
        $day = now()->subDays(90)->startOfDay();
        $end = now()->addYear()->startOfDay();

        while ($day->lessThan($end)) {
            $dates[] = $day->toDateString();
            // Reassigned (not mutated in place): now() is CarbonImmutable
            // app-wide (see AppServiceProvider), so bare addDay() loops forever.
            $day = $day->addDay();
        }

        return $dates;
    }
}
