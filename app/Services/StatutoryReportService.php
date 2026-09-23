<?php

namespace App\Services;

use App\Events\StatutoryReportFailed;
use App\Events\StatutoryReportReady;
use App\Exceptions\AvailabilityException;
use App\Exports\StatutoryExport;
use App\Jobs\GenerateStatutoryReportJob;
use App\Models\Branch;
use App\Models\RegistrationCard;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\StatutoryReport;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Authority guest registers. Same (branch, kind, range) always
 * resolves to the same file via the idempotency key; the stored hash
 * evidences tampering. Incomplete rows ship in the annex sheet —
 * never silently dropped.
 */
class StatutoryReportService
{
    public const KINDS = ['immigration', 'police', 'tourism_board'];

    public function generate(Branch $branch, string $kind, string $from, string $to, User $by): StatutoryReport
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new AvailabilityException('STATUTORY_KIND', "Unknown report kind {$kind}.");
        }

        if ($to < $from) {
            throw new AvailabilityException('STATUTORY_RANGE', 'Report end must be on or after the start.');
        }

        $key = StatutoryReport::keyFor($branch->id, $kind, $from, $to);

        try {
            $report = StatutoryReport::firstOrCreate(
                ['idempotency_key' => $key],
                [
                    'branch_id' => $branch->id,
                    'kind' => $kind,
                    'period_from' => $from,
                    'period_to' => $to,
                    'status' => StatutoryReport::STATUS_QUEUED,
                    'generated_by' => $by->id,
                ],
            );
        } catch (QueryException $e) {
            $code = $e->getPrevious()?->getCode();

            if ($code !== '23000' && $code !== '23505') {
                throw $e;
            }

            $report = StatutoryReport::where('idempotency_key', $key)->firstOrFail();
        }

        if ($report->status === StatutoryReport::STATUS_READY) {
            return $report;
        }

        GenerateStatutoryReportJob::dispatch($report->id);

        return $report->fresh() ?? $report;
    }

    public function build(StatutoryReport $report): StatutoryReport
    {
        return DB::transaction(function () use ($report) {
            $locked = StatutoryReport::where('id', $report->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === StatutoryReport::STATUS_READY) {
                return $locked;
            }

            $branch = Branch::findOrFail($locked->branch_id);
            $built = $this->buildRows($branch, $locked->kind, $locked->period_from->toDateString(), $locked->period_to->toDateString());

            $path = "statutory/{$branch->id}/{$locked->kind}-{$locked->period_from->toDateString()}_{$locked->period_to->toDateString()}.xlsx";

            if (! Excel::store(new StatutoryExport($locked->kind, $built['rows'], $built['annex']), $path, 'r2')) {
                throw new AvailabilityException('STATUTORY_STORE', 'Report file could not be stored.');
            }

            $bytes = Storage::disk('r2')->get($path);

            if (! is_string($bytes)) {
                throw new AvailabilityException('STATUTORY_STORE', 'Stored report could not be read back.');
            }

            $locked->update([
                'status' => StatutoryReport::STATUS_READY,
                'file_path' => $path,
                'file_hash' => hash('sha256', $bytes),
                'summary' => [
                    'rows' => count($built['rows']),
                    'annex' => count($built['annex']),
                    'completeness_bps' => $built['completeness_bps'],
                ],
            ]);

            event(new StatutoryReportReady($locked->fresh() ?? $locked));

            return $locked->fresh() ?? $locked;
        });
    }

    public function fail(StatutoryReport $report, string $error): void
    {
        $report->update(['status' => StatutoryReport::STATUS_FAILED]);

        event(new StatutoryReportFailed($report->fresh() ?? $report, $error));
    }

    public function downloadUrl(StatutoryReport $report, User $by): string
    {
        if ($report->status !== StatutoryReport::STATUS_READY || ! is_string($report->file_path)) {
            throw new AvailabilityException('STATUTORY_STATE', 'Only ready reports can be downloaded.');
        }

        activity('privacy')
            ->causedBy($by)
            ->withProperties(['report_id' => $report->id, 'kind' => $report->kind])
            ->log("Statutory report downloaded ({$report->kind}).");

        return Storage::disk('r2')->temporaryUrl($report->file_path, now()->addMinutes(15));
    }

    /**
     * @return array{rows: list<array<string, mixed>>, annex: list<array<string, mixed>>, completeness_bps: int}
     */
    public function buildRows(Branch $branch, string $kind, string $from, string $to): array
    {
        $stays = Reservation::forBranch($branch->id)
            ->whereIn('status', ['checked_in', 'checked_out'])
            ->whereBetween('check_in_date', [$from, $to])
            ->with(['guest'])
            ->orderBy('check_in_date')
            ->orderBy('id')
            ->get();

        $rows = [];
        $annex = [];

        foreach ($stays as $stay) {
            $guest = $stay->guest;

            $nationality = $guest?->nationality;
            $nationality = is_string($nationality) ? $nationality : null;
            $cardType = RegistrationCard::where('reservation_id', $stay->id)->value('id_type');
            $cardNumber = RegistrationCard::where('reservation_id', $stay->id)->value('id_number');
            $idType = is_string($cardType) ? $cardType : $guest?->id_type;
            $idType = is_string($idType) ? $idType : null;
            $idNumber = is_string($cardNumber) ? $cardNumber : $guest?->id_number;
            $idNumber = is_string($idNumber) ? $idNumber : null;

            $missing = [];
            if ($kind !== 'tourism_board') {
                foreach (['nationality' => $nationality, 'id_type' => $idType, 'id_number' => $idNumber] as $field => $value) {
                    if ($value === null || $value === '') {
                        $missing[] = $field;
                    }
                }
            } elseif ($stay->total_amount <= 0) {
                $missing[] = 'total_amount';
            }

            $dob = $guest?->date_of_birth;
            $nights = max(1, (int) $stay->check_in_date->diffInDays($stay->check_out_date));
            $roomNumber = $stay->room_id !== null
                ? Room::where('id', $stay->room_id)->value('number')
                : null;
            $roomNumber = is_string($roomNumber) ? $roomNumber : '';

            $rows[] = [
                'confirmation' => $stay->confirmation_number,
                'guest' => $stay->guest_name,
                'nationality' => $nationality ?? '',
                'id_type' => $idType ?? '',
                'id_number' => $idNumber ?? '',
                'check_in' => $stay->check_in_date->toDateString(),
                'check_out' => $stay->check_out_date->toDateString(),
                'room' => $roomNumber,
                'dob' => $dob instanceof \DateTimeInterface ? $dob->format('Y-m-d') : '',
                'phone' => $stay->guest_phone ?? '',
                'email' => $stay->guest_email ?? '',
                'adults' => $stay->adults,
                'source' => $stay->source,
                'nights' => $nights,
                'total' => $stay->total_amount,
            ];

            if ($missing !== []) {
                $annex[] = [
                    'confirmation' => $stay->confirmation_number,
                    'guest' => $stay->guest_name,
                    'missing' => $missing,
                ];
            }
        }

        $complete = count($rows) - count($annex);

        return [
            'rows' => $rows,
            'annex' => $annex,
            'completeness_bps' => count($rows) > 0 ? (int) round($complete * 10000 / count($rows)) : 10000,
        ];
    }
}
