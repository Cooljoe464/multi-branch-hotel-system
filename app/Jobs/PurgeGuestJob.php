<?php

namespace App\Jobs;

use App\Events\PurgeCompleted;
use App\Models\DsarRequest;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Reservation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Erasure fulfillment: null PII across guest, reservations and folios,
 * destroy identity rows + R2 scans, keep every amount. Journal and
 * trial balances are never touched (legal hold). Idempotent: fulfilled
 * requests and already-redacted guests converge to the same receipt.
 */
class PurgeGuestJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $dsarId,
    ) {
        $this->onQueue('crm');
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        return DB::transaction(function () {
            $dsar = DsarRequest::where('id', $this->dsarId)->lockForUpdate()->firstOrFail();

            if ($dsar->status === DsarRequest::STATUS_FULFILLED && is_array($dsar->result)) {
                return $dsar->result;
            }

            $guest = Guest::withTrashed()->findOrFail($dsar->guest_id);
            $tag = "REDACTED-{$guest->id}";

            $guest->update([
                'first_name' => 'Redacted',
                'last_name' => $tag,
                'email' => "redacted-{$guest->id}@deleted.local",
                'phone' => null,
                'date_of_birth' => null,
                'nationality' => null,
                'id_type' => null,
                'id_number' => null,
                'company' => null,
                'job_title' => null,
                'dietary_restrictions' => null,
                'special_notes' => null,
                'internal_notes' => null,
                'metadata' => null,
            ]);

            $guest->preferences()->delete();

            $scans = 0;
            foreach ($guest->identityDocuments()->get() as $doc) {
                if (is_string($doc->scan_path) && $doc->scan_path !== '' && Storage::disk('r2')->exists($doc->scan_path)) {
                    Storage::disk('r2')->delete($doc->scan_path);
                    $scans++;
                }

                $doc->delete();
            }

            $reservations = Reservation::where('guest_id', $guest->id)->update([
                'guest_email' => null,
                'guest_phone' => null,
                'guest_notes' => null,
            ]);

            $folios = Folio::whereIn('reservation_id', function (QueryBuilder $sub) use ($guest) {
                $sub->select('id')->from('reservations')->where('guest_id', $guest->id);
            })->update(['guest_name' => $tag]);

            $consents = 0;
            if (Schema::hasTable('consents')) {
                $consents = DB::table('consents')->where('guest_id', $guest->id)->delete();
            }

            $receipt = [
                'guest_id' => $guest->id,
                'purged_at' => now()->toDateTimeString(),
                'reservations_scrubbed' => $reservations,
                'folios_scrubbed' => $folios,
                'scans_destroyed' => $scans,
                'consents_removed' => $consents,
                'backup_note' => 'Rotating backups (30 days) may still hold ciphertext; restores re-run this purge automatically.',
            ];

            $dsar->update([
                'status' => DsarRequest::STATUS_FULFILLED,
                'result' => $receipt,
            ]);

            activity('privacy')
                ->withProperties(['dsar_id' => $dsar->id, 'guest_id' => $guest->id])
                ->log("Purge completed for guest {$guest->id}.");

            event(new PurgeCompleted($dsar->fresh() ?? $dsar, $receipt));

            return $receipt;
        });
    }
}
