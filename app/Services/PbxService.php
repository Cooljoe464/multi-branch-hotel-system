<?php

namespace App\Services;

use App\Events\CallPosted;
use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\CallRecord;
use App\Models\Folio;
use App\Models\Reservation;
use App\Models\TelecomRate;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * PBX call billing. CDR webhooks carry HMAC-SHA256 over the raw
 * body (branch cdr_secret); rating is longest-prefix match and
 * posting is idempotent on cdr_id — a duplicate delivery returns
 * the original record without touching the folio twice.
 */
class PbxService
{
    /**
     * @param  array{cdr_id: string, extension: string, destination: string, duration_secs: int, reservation_id: int|null}  $cdr
     */
    public function ingest(Branch $branch, array $cdr, string $signature, string $rawBody): CallRecord
    {
        $secret = $branch->getAttribute('cdr_secret');

        if (! is_string($secret) || $secret === '') {
            throw new AvailabilityException('PBX_UNCONFIGURED', 'Call billing is not configured for this property.');
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        if (! hash_equals($expected, $signature)) {
            throw new AvailabilityException('PBX_SIGNATURE', 'CDR signature mismatch.');
        }

        return DB::transaction(function () use ($branch, $cdr) {
            $existing = CallRecord::where('cdr_id', $cdr['cdr_id'])->lockForUpdate()->first();

            if ($existing) {
                return $existing;
            }

            $rate = $this->rateFor($branch, $cdr['destination']);
            $minutes = (int) ceil($cdr['duration_secs'] / 60);
            $charge = $minutes * $rate;

            $reservation = null;
            if (! empty($cdr['reservation_id'])) {
                $reservation = Reservation::where('id', $cdr['reservation_id'])
                    ->where('branch_id', $branch->id)
                    ->first();
            }

            $record = CallRecord::create([
                'branch_id' => $branch->id,
                'reservation_id' => $reservation?->id,
                'extension' => $cdr['extension'],
                'destination' => $cdr['destination'],
                'duration_secs' => $cdr['duration_secs'],
                'charge_minor' => $charge,
                'cdr_id' => $cdr['cdr_id'],
            ]);

            if ($reservation !== null && $charge > 0) {
                $this->postToFolio($branch, $reservation, $record);
            }

            event(new CallPosted($record));

            return $record->fresh() ?? $record;
        });
    }

    public function rateFor(Branch $branch, string $destination): int
    {
        $rates = TelecomRate::where('branch_id', $branch->id)->where('is_active', true)->get();

        $best = null;
        $bestLen = -1;

        foreach ($rates as $rate) {
            if (str_starts_with($destination, $rate->destination_prefix) && strlen($rate->destination_prefix) > $bestLen) {
                $best = $rate;
                $bestLen = strlen($rate->destination_prefix);
            }
        }

        return $best instanceof TelecomRate ? $best->rate_minor_per_min : 0;
    }

    private function postToFolio(Branch $branch, Reservation $reservation, CallRecord $record): void
    {
        $folio = Folio::where('reservation_id', $reservation->id)->first()
            ?? (new FolioService)->createFolio($branch->id, $reservation->id, null, "Guest Folio: {$reservation->guest_name}");

        $alreadyPosted = Transaction::where('folio_id', $folio->id)
            ->where('category', 'telecom')
            ->where('description', "Call {$record->destination} ({$record->cdr_id})")
            ->where('is_voided', false)
            ->exists();

        if ($alreadyPosted) {
            return;
        }

        (new FolioService)->postDebit(
            $folio,
            'telecom',
            "Call {$record->destination} ({$record->cdr_id})",
            $record->charge_minor,
            null,
            windowCode: 'telecom',
            journalEvent: 'telecom.call',
        );
    }
}
