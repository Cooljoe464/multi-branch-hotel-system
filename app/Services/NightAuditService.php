<?php

namespace App\Services;

use App\Events\NightAuditCompleted;
use App\Events\NightAuditFailed;
use App\Events\NightAuditStepCompleted;
use App\Models\Branch;
use App\Models\Branding;
use App\Models\BusinessDate;
use App\Models\DailyLedger;
use App\Models\Folio;
use App\Models\GroupLedger;
use App\Models\NightAuditRun;
use App\Models\Reservation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

class NightAuditService
{
    private ?Branch $branch = null;

    public function forBranch(Branch $branch): self
    {
        $this->branch = $branch;

        return $this;
    }

    /**
     * Run (or resume, or replay-safe return) the audit for a business date.
     * Every step commits independently and records progress, so a crash
     * re-enters mid-flight without double-posting. Concurrent runs for the
     * same date collapse onto the single run row.
     */
    public function run(string $businessDate, ?string $idempotencyKey = null, ?User $runBy = null): NightAuditRun
    {
        $branch = $this->branch;
        if (! $branch) {
            throw new LogicException('Branch must be set before running night audit.');
        }

        $date = Carbon::parse($businessDate)->toDateString();
        $key = $idempotencyKey ?? "nightaudit.{$branch->id}.{$date}";

        $run = $this->claimRun($branch, $date, $key, $runBy);

        if ($run->isFinished()) {
            return $run->fresh() ?? $run;
        }

        // A directly-completed ledger (legacy path) means the work is done.
        $ledger = $this->ledgerFor($branch, $date);
        if ($ledger && $ledger->status === 'completed') {
            return $this->finishRun($run, $ledger, []);
        }

        $steps = $run->steps ?? [];
        $result = $run->result ?? [];
        $roomTotals = $this->roomTotals($result);

        try {
            if (! isset($steps['lock_date'])) {
                $this->ensureDateRow($branch, $date);
                $steps['lock_date'] = ['status' => 'done', 'date' => $date];
                $this->saveSteps($run, $steps);
            }

            if (! isset($steps['post_room'])) {
                $roomResult = $this->postRoomCharges(Carbon::parse($date));
                $steps['post_room'] = ['status' => 'done'] + $roomResult;
                $result = array_merge($result, $roomResult);
                $roomTotals = $roomResult;
                $this->saveSteps($run, $steps);
                $this->saveResult($run, $result);
                event(new NightAuditStepCompleted($run, 'post_room', $roomResult['posted']));
            }

            if (! isset($steps['no_show'])) {
                $noShow = $this->applyNoShows($branch, $date, $runBy);
                $steps['no_show'] = ['status' => 'done'] + $noShow;
                $this->saveSteps($run, $steps);
                event(new NightAuditStepCompleted($run, 'no_show', $noShow['processed']));
            }

            if (! isset($steps['day_use'])) {
                $dayUse = $this->applyDayUse($branch, $date);
                $steps['day_use'] = ['status' => 'done'] + $dayUse;
                $this->saveSteps($run, $steps);
                event(new NightAuditStepCompleted($run, 'day_use', $dayUse['processed']));
            }

            if (! isset($steps['early_departure'])) {
                $early = $this->applyEarlyDepartures($branch, $date);
                $steps['early_departure'] = ['status' => 'done'] + $early;
                $this->saveSteps($run, $steps);
                event(new NightAuditStepCompleted($run, 'early_departure', $early['processed']));
            }

            if (! isset($steps['trial_balance'])) {
                $trial = (new TrialBalanceService)->close($branch, $date);
                $steps['trial_balance'] = ['status' => 'done', 'balanced' => $trial->balanced];
                $this->saveSteps($run, $steps);
                event(new NightAuditStepCompleted($run, 'trial_balance', $trial->balanced ? 1 : 0));

                if (! $trial->balanced) {
                    return $this->failRun($run, 'Trial balance does not reconcile.');
                }
            } elseif (! $this->stepBalanced($steps, 'trial_balance')) {
                return $this->failRun($run, 'Trial balance does not reconcile.');
            }

            if (! isset($steps['advance'])) {
                $this->advanceIfCurrent($branch, $date, $runBy);
                $steps['advance'] = ['status' => 'done'];
                $this->saveSteps($run, $steps);
            }

            $ledger = $this->writeLedger($branch, $date, $roomTotals);

            return $this->finishRun($run, $ledger, $result);
        } catch (\Throwable $e) {
            report($e);

            return $this->failRun($run, $e->getMessage());
        }
    }

    /**
     * @return array{posted: int, errors: list<array{reservation_id: int, error: string}>, total_room_revenue: int, total_tax: int}
     */
    public function postRoomCharges(Carbon $businessDate): array
    {
        $branch = $this->branch;
        if (! $branch) {
            throw new LogicException('Branch must be set before posting room charges.');
        }

        $date = $businessDate->toDateString();

        $reservations = Reservation::forBranch($branch->id)
            ->checkedIn()
            ->where('check_in_date', '<=', $date)
            ->where('check_out_date', '>', $date)
            ->with(['room', 'roomType', 'folio'])
            ->get();

        $posted = 0;
        $errors = [];
        $totalRoomRevenue = 0;
        $totalTax = 0;

        foreach ($reservations as $reservation) {
            try {
                $charges = $this->postRoomChargeLines($branch, $reservation, $date);

                if ($charges === []) {
                    continue;
                }

                $totalRoomRevenue += $reservation->room_rate;
                foreach ($charges as $charge) {
                    $totalTax += $charge->tax_amount;
                }
                $posted++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'reservation_id' => $reservation->id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'posted' => $posted,
            'errors' => $errors,
            'total_room_revenue' => $totalRoomRevenue,
            'total_tax' => $totalTax,
        ];
    }

    public function closeDailyLedger(Carbon $businessDate): DailyLedger
    {
        $branch = $this->branch;
        if (! $branch) {
            throw new LogicException('Branch must be set before closing daily ledger.');
        }

        $run = $this->run($businessDate->toDateString());

        $ledger = $this->ledgerFor($branch, $run->business_date->toDateString());

        if (! $ledger) {
            throw new LogicException('Night audit run produced no ledger.');
        }

        return $ledger;
    }

    public function getUnclosedLedger(?Carbon $date = null): ?DailyLedger
    {
        $branch = $this->branch;
        if (! $branch) {
            throw new LogicException('Branch must be set.');
        }

        $date = $date ?? now()->subDay();

        return DailyLedger::forBranch($branch->id)
            ->forDate($date->toDateString())
            ->first();
    }

    /**
     * Post one folio line per rate component (packages itemize; plain
     * rates post a single room line) unless this reservation was already
     * charged for the date (crash-resume safety). Returns [] when skipped.
     *
     * @return list<Transaction>
     */
    private function postRoomChargeLines(Branch $branch, Reservation $reservation, string $date): array
    {
        $folio = Folio::where('reservation_id', $reservation->id)->first();
        $folioService = new FolioService;

        if (! $folio) {
            $folio = $folioService->createFolio($branch->id, $reservation->id, null, "Guest Folio: {$reservation->guest_name}");
        }

        $alreadyPosted = Transaction::where('folio_id', $folio->id)
            ->where('business_date', $date)
            ->where('is_voided', false)
            ->where(fn ($query) => $query
                ->where('metadata->rate_night', $date)
                // Legacy rows predate the rate_night marker; plain rates
                // always posted a single room_rate line.
                ->orWhere(fn ($legacy) => $legacy->whereNull('metadata')->where('category', 'room_rate')))
            ->exists();

        if ($alreadyPosted) {
            return [];
        }

        $taxRateBps = (int) ($branch->tax_rate * 100);
        $roomNumber = $reservation->room->number ?? 'N/A';
        $components = $this->componentsForNight($reservation, $date);

        $charges = [];
        foreach ($components as $component) {
            $isRoom = $component['category'] === 'room_rate';

            $charges[] = $folioService->postDebit(
                $folio,
                $component['category'],
                $isRoom
                    ? "Room charge: {$roomNumber} - {$date}"
                    : "{$component['label']}: {$roomNumber} - {$date}",
                $component['amount_minor'],
                null,
                $isRoom ? $taxRateBps : 0,
                referenceType: null,
                referenceId: null,
                metadata: ['rate_night' => $date, 'rate_component' => $component['code']],
            );
        }

        return $charges;
    }

    /**
     * Raw JSON numerics stay integers only when they already are;
     * anything else falls back instead of casting.
     */
    private static function rawInt(mixed $value, int $default = 0): int
    {
        return is_int($value) ? $value : $default;
    }

    private static function rawString(mixed $value, string $default): string
    {
        return is_string($value) ? $value : $default;
    }

    /**
     * Frozen snapshot components for one night; plain bookings without a
     * snapshot fall back to a single room line at the reservation rate.
     * Snapshot rows are untyped JSON, so every field is normalized here
     * and the posting loop above works on exact shapes.
     *
     * @return list<array{code: string, label: string, amount_minor: int, category: string}>
     */
    private function componentsForNight(Reservation $reservation, string $date): array
    {
        $snapshot = $reservation->rate_snapshot;
        $nights = is_array($snapshot) && isset($snapshot['nights']) && is_array($snapshot['nights'])
            ? array_values($snapshot['nights'])
            : [];

        foreach ($nights as $night) {
            if (! is_array($night) || ($night['date'] ?? null) !== $date) {
                continue;
            }

            $raw = $night['components'] ?? null;

            if (! is_array($raw)) {
                continue;
            }

            $components = [];
            foreach ($raw as $item) {
                $row = is_array($item) ? $item : [];
                $components[] = [
                    'code' => self::rawString($row['code'] ?? 'extra', 'extra'),
                    'label' => self::rawString($row['label'] ?? 'Package extra', 'Package extra'),
                    'amount_minor' => self::rawInt($row['amount_minor'] ?? 0),
                    'category' => self::rawString($row['category'] ?? 'package_extra', 'package_extra'),
                ];
            }

            return $components;
        }

        return [[
            'code' => 'room',
            'label' => 'Room',
            'amount_minor' => $reservation->room_rate,
            'category' => 'room_rate',
        ]];
    }

    /**
     * @return array{processed: int, fees_minor: int}
     */
    private function applyNoShows(Branch $branch, string $date, ?User $runBy): array
    {
        $processed = 0;
        $fees = 0;
        $guarantees = new GuaranteeService;

        $reservations = Reservation::forBranch($branch->id)
            ->whereIn('status', ['pending', 'confirmed', 'reserved'])
            ->whereDate('check_in_date', $date)
            ->get();

        foreach ($reservations as $reservation) {
            $fees += $guarantees->noShowPenalty($reservation, $date, $runBy);
            $processed++;
        }

        return ['processed' => $processed, 'fees_minor' => $fees];
    }

    /**
     * @return array{processed: int, revenue_minor: int}
     */
    private function applyDayUse(Branch $branch, string $date): array
    {
        $processed = 0;
        $revenue = 0;
        $folioService = new FolioService;

        $settings = $branch->settings ?? [];
        $rateBps = $settings['day_use_rate_bps'] ?? 5000;
        $rateBps = is_int($rateBps) ? $rateBps : 5000;

        $reservations = Reservation::forBranch($branch->id)
            ->checkedIn()
            ->whereDate('check_in_date', $date)
            ->whereDate('check_out_date', $date)
            ->whereNull('audit_outcome')
            ->get();

        foreach ($reservations as $reservation) {
            $amount = (int) round($reservation->room_rate * $rateBps / 10000);

            DB::transaction(function () use ($branch, $reservation, $date, $amount, $folioService) {
                $locked = Reservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();

                if ($locked->audit_outcome !== null) {
                    return;
                }

                $folio = Folio::where('reservation_id', $locked->id)->first()
                    ?? $folioService->createFolio($branch->id, $locked->id, null, "Guest Folio: {$locked->guest_name}");

                if ($amount > 0) {
                    $folioService->postDebit(
                        $folio,
                        'day_use',
                        "Day use: {$date}",
                        $amount,
                        null, null, null, null, null, null, null,
                        'day_use.charge',
                    );
                }

                $locked->update(['audit_outcome' => 'day_use']);
            });

            $processed++;
            $revenue += $amount;
        }

        return ['processed' => $processed, 'revenue_minor' => $revenue];
    }

    /**
     * @return array{processed: int, nights_released: int}
     */
    private function applyEarlyDepartures(Branch $branch, string $date): array
    {
        $processed = 0;
        $released = 0;
        $availability = new AvailabilityService;

        $reservations = Reservation::forBranch($branch->id)
            ->checkedIn()
            ->whereNotNull('actual_check_out_at')
            ->whereDate('actual_check_out_at', '<=', $date)
            ->whereNull('audit_outcome')
            ->where('check_out_date', '>', $date)
            ->get();

        foreach ($reservations as $reservation) {
            $departure = $reservation->actual_check_out_at?->toDateString() ?? $date;

            $released += $availability->releaseFromDate($reservation, $departure);
            $reservation->update(['audit_outcome' => 'early_departure']);
            $processed++;
        }

        return ['processed' => $processed, 'nights_released' => $released];
    }

    private function claimRun(Branch $branch, string $date, string $key, ?User $runBy): NightAuditRun
    {
        return DB::transaction(function () use ($branch, $date, $key, $runBy) {
            $existing = NightAuditRun::forBranch($branch->id)
                ->where('business_date', $date)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            return NightAuditRun::create([
                'branch_id' => $branch->id,
                'business_date' => $date,
                'status' => NightAuditRun::STATUS_RUNNING,
                'idempotency_key' => $key,
                'run_by' => $runBy?->id,
            ]);
        });
    }

    private function ensureDateRow(Branch $branch, string $date): void
    {
        $row = BusinessDate::forBranch($branch->id)->where('business_date', $date)->first();

        if ($row) {
            return;
        }

        $openExists = BusinessDate::forBranch($branch->id)->open()->exists();

        BusinessDate::create([
            'branch_id' => $branch->id,
            'business_date' => $date,
            'status' => $openExists ? BusinessDate::STATUS_CLOSED : BusinessDate::STATUS_OPEN,
            'opened_at' => now(),
        ]);
    }

    private function advanceIfCurrent(Branch $branch, string $date, ?User $runBy): void
    {
        $open = BusinessDate::forBranch($branch->id)->open()->first();

        if ($open && $open->business_date->toDateString() === $date) {
            (new BusinessDateService)->advance($branch, $runBy);
        }
    }

    private function ledgerFor(Branch $branch, string $date): ?DailyLedger
    {
        return DailyLedger::forBranch($branch->id)->forDate($date)->first();
    }

    /**
     * Normalize stored step data back into the posting totals shape.
     *
     * @param  array<string, mixed>  $result
     * @return array{posted: int, errors: list<array{reservation_id: int, error: string}>, total_room_revenue: int, total_tax: int}
     */
    private function roomTotals(array $result): array
    {
        $errors = [];

        $raw = $result['errors'] ?? null;
        if (is_array($raw)) {
            foreach ($raw as $error) {
                if (! is_array($error)) {
                    continue;
                }
                $id = $error['reservation_id'] ?? null;
                $message = $error['error'] ?? null;
                if (is_int($id) && is_string($message)) {
                    $errors[] = ['reservation_id' => $id, 'error' => $message];
                }
            }
        }

        $posted = $result['posted'] ?? null;
        $revenue = $result['total_room_revenue'] ?? null;
        $tax = $result['total_tax'] ?? null;

        return [
            'posted' => is_int($posted) ? $posted : 0,
            'errors' => $errors,
            'total_room_revenue' => is_int($revenue) ? $revenue : 0,
            'total_tax' => is_int($tax) ? $tax : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $steps
     */
    private function stepBalanced(array $steps, string $step): bool
    {
        $entry = $steps[$step] ?? null;

        return is_array($entry) && ($entry['balanced'] ?? null) === true;
    }

    /**
     * @param  array{posted: int, errors: list<array{reservation_id: int, error: string}>, total_room_revenue: int, total_tax: int}  $roomTotals
     */
    private function writeLedger(Branch $branch, string $date, array $roomTotals): DailyLedger
    {
        $ledger = $this->ledgerFor($branch, $date);

        if (! $ledger) {
            $ledger = DailyLedger::create([
                'branch_id' => $branch->id,
                'currency_code' => $branch->currency_code,
                'business_date' => $date,
                'status' => 'pending',
            ]);
        }

        if ($ledger->status === 'completed') {
            return $ledger;
        }

        if ($ledger->status !== 'in_progress') {
            $ledger->markInProgress();
        }

        $totalOtherCharges = (int) $branch->posCharges()
            ->where('business_date', $date)
            ->sum('total');

        $totalPayments = (int) $branch->paymentTransactions()
            ->where('status', 'success')
            ->where('business_date', $date)
            ->sum('amount');

        $roomRevenue = $roomTotals['total_room_revenue'];
        $roomTax = $roomTotals['total_tax'];
        $netRevenue = $roomRevenue + $roomTax + $totalOtherCharges - $totalPayments;

        $ledger->markCompleted([
            'rooms_posted' => $roomTotals['posted'],
            'total_room_revenue' => $roomRevenue,
            'total_tax' => $roomTax,
            'total_other_charges' => $totalOtherCharges,
            'total_payments' => $totalPayments,
            'net_revenue' => $netRevenue,
        ]);

        if (! empty($roomTotals['errors'])) {
            $ledger->update(['errors' => $roomTotals['errors']]);
        }

        try {
            $auditFlagService = new AuditFlagService;
            $auditFlagService->generate($branch, Carbon::parse($date));
        } catch (\Throwable $e) {
            // Audit flag generation is non-critical
        }

        try {
            $currencyCode = $branch->currency_code ?: Branding::instance()->currency_code;

            GroupLedger::updateOrCreate(
                ['branch_id' => $branch->id, 'business_date' => $date],
                [
                    'total_room_revenue' => $roomRevenue,
                    'total_pos_revenue' => $totalOtherCharges,
                    'total_tax' => $roomTax,
                    'total_payments' => $totalPayments,
                    'net_revenue' => $netRevenue,
                    'currency_code' => $currencyCode,
                    'exchange_rate_to_group' => 1.0,
                ]
            );
        } catch (\Throwable $e) {
            // Group ledger population is non-critical
        }

        return $ledger->fresh() ?? $ledger;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function finishRun(NightAuditRun $run, DailyLedger $ledger, array $result): NightAuditRun
    {
        $run->update([
            'status' => NightAuditRun::STATUS_RECONCILED,
            'result' => array_merge($result, ['ledger_id' => $ledger->id]),
        ]);

        $fresh = $run->fresh() ?? $run;
        event(new NightAuditCompleted($fresh));

        return $fresh;
    }

    private function failRun(NightAuditRun $run, string $reason): NightAuditRun
    {
        $run->update([
            'status' => NightAuditRun::STATUS_FAILED,
            'result' => array_merge($run->result ?? [], ['failure' => $reason]),
        ]);

        $fresh = $run->fresh() ?? $run;
        event(new NightAuditFailed($fresh, $reason));

        return $fresh;
    }

    /**
     * @param  array<string, mixed>  $steps
     */
    private function saveSteps(NightAuditRun $run, array $steps): void
    {
        $run->update(['steps' => $steps]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function saveResult(NightAuditRun $run, array $result): void
    {
        $run->update(['result' => $result]);
    }
}
