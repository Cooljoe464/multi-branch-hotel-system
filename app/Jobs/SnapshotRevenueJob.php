<?php

namespace App\Jobs;

use App\Events\RevenueSnapshotReady;
use App\Models\Branch;
use App\Models\DailyLedger;
use App\Models\JournalEntry;
use App\Models\Reservation;
use App\Models\ReservationNight;
use App\Models\RevenueSnapshot;
use App\Models\Room;
use App\Services\AvailabilityService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Rebuild the daily revenue grain per stay date. Past dates carry
 * audited actuals from daily ledgers; future dates carry on-the-books
 * pickup from live reservations. Upserts by (branch, stay, snapshot),
 * so re-runs rewrite the same rows instead of duplicating.
 */
class SnapshotRevenueJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('reports');
    }

    /**
     * @return array{branches: int, rows: int}
     */
    public function handle(): array
    {
        $today = Carbon::today()->toDateString();
        $from = Carbon::today()->subDays(30)->toDateString();
        $to = Carbon::today()->addDays(90)->toDateString();

        $branches = 0;
        $rows = 0;

        Branch::where('is_active', true)->orderBy('id')->chunkById(50, function ($chunk) use ($today, $from, $to, &$branches, &$rows) {
            foreach ($chunk as $branch) {
                $rows += $this->snapshotBranch($branch, $today, $from, $to);
                $branches++;

                event(new RevenueSnapshotReady($branch, $today));
            }
        });

        Log::info('Revenue snapshots rebuilt.', ['branches' => $branches, 'rows' => $rows]);

        return ['branches' => $branches, 'rows' => $rows];
    }

    private function snapshotBranch(Branch $branch, string $today, string $from, string $to): int
    {
        $available = Room::forBranch($branch->id)
            ->where('is_active', true)
            ->where('status', '!=', 'out_of_order')
            ->count();

        // OTB nights with per-night revenue + source/segment splits.
        // Eager-loaded (not joined) so Reservation casts apply to every
        // field the math reads.
        $otb = ReservationNight::whereBetween('stay_date', [$from, $to])
            ->whereHas('reservation', fn ($q) => $q
                ->where('branch_id', $branch->id)
                ->whereIn('status', ['pending', 'confirmed', 'reserved', 'checked_in', 'checked_out']))
            ->with('reservation')
            ->get()
            ->groupBy(fn (ReservationNight $row) => $row->stay_date->toDateString());

        $count = 0;
        foreach ((new AvailabilityService)->nights($from, Carbon::parse($to)->addDay()->toDateString()) as $date) {
            $ledger = $date <= $today
                ? DailyLedger::forBranch($branch->id)->forDate($date)->completed()->first()
                : null;

            if ($ledger) {
                $row = [
                    'rooms_available' => $available,
                    'rooms_sold' => $ledger->rooms_posted,
                    'room_revenue_minor' => $ledger->total_room_revenue,
                    'total_revenue_minor' => $ledger->total_room_revenue + $ledger->total_other_charges,
                    'gop_expense_minor' => $this->gopExpense($branch, $date),
                    'by_segment' => null,
                    'by_source' => null,
                ];
            } else {
                $row = $this->otbRow($available, $otb->get($date, collect()));
            }

            RevenueSnapshot::updateOrCreate(
                ['branch_id' => $branch->id, 'stay_date' => $date, 'snapshot_date' => $today],
                $row,
            );
            $count++;
        }

        return $count;
    }

    /**
     * @param  Collection<int|string, mixed>  $nights
     * @return array{rooms_available: int, rooms_sold: int, room_revenue_minor: int, total_revenue_minor: int, gop_expense_minor: int, by_segment: array<string, array{nights: int, revenue_minor: int}>|null, by_source: array<string, array{nights: int, revenue_minor: int}>|null}
     */
    private function otbRow(int $available, $nights): array
    {
        $sold = 0;
        $revenue = 0;
        $bySource = [];
        $bySegment = [];

        foreach ($nights as $night) {
            if (! $night instanceof ReservationNight) {
                continue;
            }

            $stay = $night->reservation;

            if ($stay === null) {
                continue;
            }

            $rate = $this->nightRate($stay, $night->stay_date->toDateString());
            $sold++;
            $revenue += $rate;

            $source = (string) $stay->source;
            $source = $source !== '' ? $source : 'direct';
            $segment = $this->segmentFor($stay);

            $bySource[$source]['nights'] = ($bySource[$source]['nights'] ?? 0) + 1;
            $bySource[$source]['revenue_minor'] = ($bySource[$source]['revenue_minor'] ?? 0) + $rate;
            $bySegment[$segment]['nights'] = ($bySegment[$segment]['nights'] ?? 0) + 1;
            $bySegment[$segment]['revenue_minor'] = ($bySegment[$segment]['revenue_minor'] ?? 0) + $rate;
        }

        return [
            'rooms_available' => $available,
            'rooms_sold' => $sold,
            'room_revenue_minor' => $revenue,
            'total_revenue_minor' => $revenue,
            'gop_expense_minor' => 0,
            'by_segment' => $bySegment === [] ? null : $bySegment,
            'by_source' => $bySource === [] ? null : $bySource,
        ];
    }

    private function nightRate(Reservation $stay, string $stayDate): int
    {
        $snapshot = $stay->rate_snapshot;

        if (is_array($snapshot)) {
            $rows = $snapshot['nights'] ?? null;
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (is_array($row) && ($row['date'] ?? null) === $stayDate) {
                        $total = $row['total_minor'] ?? 0;

                        return is_int($total) ? $total : 0;
                    }
                }
            }
        }

        return $stay->room_rate;
    }

    private function segmentFor(Reservation $stay): string
    {
        if ($stay->corporate_account_id !== null) {
            return 'corporate';
        }

        if ($stay->is_group_booking) {
            return 'group';
        }

        return $stay->source === 'direct' ? 'retail' : 'ota';
    }

    private function gopExpense(Branch $branch, string $date): int
    {
        $total = JournalEntry::where('branch_id', $branch->id)
            ->where('business_date', $date)
            ->where('debit_account', 'like', '5%')
            ->sum('amount_minor');

        return (int) $total;
    }
}
