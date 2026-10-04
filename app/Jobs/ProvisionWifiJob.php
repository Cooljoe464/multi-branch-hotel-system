<?php

namespace App\Jobs;

use App\Events\WifiIssued;
use App\Models\Reservation;
use App\Models\WifiSession;
use App\Services\HotspotService;
use App\Services\MikrotikService;
use App\Services\RadiusService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProvisionWifiJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $reservationId)
    {
        $queue = config('hotspot.queue', 'network');
        $this->onQueue(is_string($queue) ? $queue : 'network');
    }

    public function handle(HotspotService $hotspot, RadiusService $radius, MikrotikService $mikrotik): void
    {
        $reservation = Reservation::with(['branch'])->find($this->reservationId);

        if (! $reservation || $reservation->status !== 'checked_in') {
            return;
        }

        $branch = $reservation->branch;

        try {
            $selection = $hotspot->attachReservation($reservation);
            $tier = $selection->tier ?? $hotspot->defaultTier($branch);

            if (! $tier) {
                Log::warning('Hotspot provision skipped: no tier', ['reservation' => $reservation->confirmation_number]);

                return;
            }

            $length = config('hotspot.voucher_length', 12);
            $voucher = $hotspot->generateVoucher(is_int($length) ? $length : 12);
            $password = $hotspot->generatePassword();

            $session = WifiSession::create([
                'branch_id' => $branch->id,
                'reservation_id' => $reservation->id,
                'hotspot_tier_id' => $tier->id,
                'voucher' => $voucher,
                'username' => $voucher,
                'password' => $password,
                'folio_transaction_id' => $selection?->folio_transaction_id,
                'expires_at' => $reservation->check_out_date->copy()->addDay()->startOfDay(),
                'provisioned_at' => now(),
            ]);

            $selection?->update(['voucher' => $voucher]);

            $timeout = (string) max(60, (int) now()->diffInSeconds($session->expires_at, true));

            $radius->createUser($voucher, $password, $tier, $timeout);
            $mikrotik->ensureHotspotUser($branch, $voucher, $tier->mikrotikRateLimit());

            event(new WifiIssued($session->fresh() ?? $session));
        } catch (\Throwable $e) {
            Log::warning('Hotspot provision failed (degraded)', [
                'reservation' => $reservation->confirmation_number,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
