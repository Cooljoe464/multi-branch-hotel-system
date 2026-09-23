<?php

namespace App\Services;

use App\Events\DsarFulfilled;
use App\Events\DsarReceived;
use App\Exceptions\AvailabilityException;
use App\Jobs\PurgeGuestJob;
use App\Models\Branch;
use App\Models\DsarRequest;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * DSAR intake and fulfillment. Access/portability compile an export
 * bundle (stored private, served signed); erasure purges PII through
 * PurgeGuestJob while journal amounts and folio totals stay intact.
 */
class DsarService
{
    public function intake(?Branch $branch, Guest $guest, string $kind, ?User $by = null): DsarRequest
    {
        if (! in_array($kind, [DsarRequest::KIND_ACCESS, DsarRequest::KIND_ERASURE, DsarRequest::KIND_PORTABILITY], true)) {
            throw new AvailabilityException('DSAR_KIND', "Unknown DSAR kind {$kind}.");
        }

        $request = DsarRequest::create([
            'branch_id' => $branch?->id,
            'guest_id' => $guest->id,
            'kind' => $kind,
            'status' => DsarRequest::STATUS_OPEN,
            'requested_by' => $by?->id,
        ]);

        activity('privacy')
            ->performedOn($guest)
            ->causedBy($by)
            ->withProperties(['dsar_id' => $request->id, 'kind' => $kind])
            ->log("DSAR received ({$kind}).");

        event(new DsarReceived($request->fresh() ?? $request));

        return $request->fresh() ?? $request;
    }

    public function fulfill(DsarRequest $request, User $by): DsarRequest
    {
        return DB::transaction(function () use ($request, $by) {
            $locked = DsarRequest::where('id', $request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== DsarRequest::STATUS_OPEN) {
                return $locked;
            }

            $guest = Guest::withTrashed()->findOrFail($locked->guest_id);

            if ($locked->kind === DsarRequest::KIND_ERASURE) {
                $this->guardErasure($guest);

                PurgeGuestJob::dispatch($locked->id);

                $locked->update(['fulfilled_by' => $by->id]);

                activity('privacy')
                    ->performedOn($guest)
                    ->causedBy($by)
                    ->withProperties(['dsar_id' => $locked->id])
                    ->log('Erasure queued for purge.');

                return $locked->fresh() ?? $locked;
            }

            $bundle = $this->exportBundle($guest);
            $encoded = json_encode($bundle, JSON_PRETTY_PRINT);

            if ($encoded === false) {
                throw new AvailabilityException('DSAR_BUNDLE', 'Export bundle could not be encoded.');
            }

            $path = "dsar/{$guest->id}/{$locked->kind}-{$locked->id}.json";
            Storage::disk('r2')->put($path, $encoded);

            $locked->update([
                'status' => DsarRequest::STATUS_FULFILLED,
                'fulfilled_by' => $by->id,
                'result' => [
                    'file' => $path,
                    'hash' => hash('sha256', $encoded),
                    'counts' => $bundle['counts'],
                ],
            ]);

            activity('privacy')
                ->performedOn($guest)
                ->causedBy($by)
                ->withProperties(['dsar_id' => $locked->id, 'kind' => $locked->kind])
                ->log("DSAR fulfilled ({$locked->kind}).");

            event(new DsarFulfilled($locked->fresh() ?? $locked));

            return $locked->fresh() ?? $locked;
        });
    }

    public function reject(DsarRequest $request, User $by, string $reason): DsarRequest
    {
        return DB::transaction(function () use ($request, $by, $reason) {
            $locked = DsarRequest::where('id', $request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== DsarRequest::STATUS_OPEN) {
                return $locked;
            }

            $locked->update([
                'status' => DsarRequest::STATUS_REJECTED,
                'fulfilled_by' => $by->id,
                'result' => ['reason' => $reason],
            ]);

            activity('privacy')
                ->causedBy($by)
                ->withProperties(['dsar_id' => $locked->id, 'reason' => $reason])
                ->log('DSAR rejected.');

            return $locked->fresh() ?? $locked;
        });
    }

    public function downloadUrl(DsarRequest $request, User $by): string
    {
        if ($request->status !== DsarRequest::STATUS_FULFILLED) {
            throw new AvailabilityException('DSAR_STATE', 'Only fulfilled requests have bundles.');
        }

        $result = is_array($request->result) ? $request->result : null;
        $file = is_array($result) && is_string($result['file'] ?? null) ? $result['file'] : null;

        if ($file === null) {
            throw new AvailabilityException('DSAR_STATE', 'This request carries no bundle (erasure receipts download nothing).');
        }

        activity('privacy')
            ->causedBy($by)
            ->withProperties(['dsar_id' => $request->id])
            ->log('DSAR bundle downloaded.');

        return Storage::disk('r2')->temporaryUrl($file, now()->addMinutes(15));
    }

    /**
     * @return array{guest: array<string, mixed>, preferences: list<array<string, mixed>>, reservations: list<array<string, mixed>>, consents: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function exportBundle(Guest $guest): array
    {
        $preferences = [];
        foreach ($guest->preferences()->orderBy('id')->get() as $preference) {
            $preferences[] = [
                'category' => $preference->category,
                'key' => $preference->key,
                'value' => $preference->value,
            ];
        }

        $reservations = [];
        foreach ($guest->reservations()->orderBy('id')->get() as $reservation) {
            $reservations[] = [
                'confirmation_number' => $reservation->confirmation_number,
                'check_in_date' => $reservation->check_in_date->toDateString(),
                'check_out_date' => $reservation->check_out_date->toDateString(),
                'room_rate' => $reservation->room_rate,
                'total_amount' => $reservation->total_amount,
                'status' => $reservation->status,
                'source' => $reservation->source,
            ];
        }

        $consents = [];
        if (Schema::hasTable('consents')) {
            foreach (DB::table('consents')->where('guest_id', $guest->id)->orderBy('id')->get() as $row) {
                $channel = $row->channel ?? null;
                $purpose = $row->purpose ?? null;
                $granted = $row->granted ?? null;
                $at = $row->at ?? null;

                $consents[] = [
                    'channel' => is_string($channel) ? $channel : '',
                    'purpose' => is_string($purpose) ? $purpose : '',
                    'granted' => (bool) $granted,
                    'at' => is_string($at) ? $at : null,
                ];
            }
        }

        return [
            'guest' => $guest->only([
                'first_name', 'last_name', 'email', 'phone', 'date_of_birth',
                'nationality', 'company', 'vip_status',
                'total_stays', 'total_nights', 'total_spent',
            ]),
            'preferences' => $preferences,
            'reservations' => $reservations,
            'consents' => $consents,
            'counts' => [
                'preferences' => count($preferences),
                'reservations' => count($reservations),
                'consents' => count($consents),
            ],
        ];
    }

    private function guardErasure(Guest $guest): void
    {
        $inHouse = Reservation::where('guest_id', $guest->id)
            ->where('status', 'checked_in')
            ->exists();

        $openBalance = Folio::whereIn('reservation_id', function (QueryBuilder $sub) use ($guest) {
            $sub->select('id')->from('reservations')->where('guest_id', $guest->id);
        })->where('status', 'open')->sum('balance');

        if ($inHouse || (int) $openBalance > 0) {
            throw new AvailabilityException('OPEN_FOLIO', 'Erasure waits for checkout and a settled folio.');
        }
    }
}
