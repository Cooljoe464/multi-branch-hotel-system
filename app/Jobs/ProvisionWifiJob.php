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
use RuntimeException;

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

            $session = WifiSession::query()
                ->where('reservation_id', $reservation->id)
                ->whereNull('revoked_at')
                ->whereNull('deprovisioned_at')
                ->latest('id')
                ->first();

            if ($session === null) {
                $length = config('hotspot.voucher_length', 12);
                $voucher = $hotspot->generateVoucher(is_int($length) ? $length : 12);
                $session = WifiSession::create([
                    'branch_id' => $branch->id,
                    'reservation_id' => $reservation->id,
                    'hotspot_tier_id' => $tier->id,
                    'voucher' => $voucher,
                    'username' => $voucher,
                    'password' => $hotspot->generatePassword(),
                    'folio_transaction_id' => $selection?->folio_transaction_id,
                    'expires_at' => $reservation->check_out_date->copy()->addDay()->startOfDay(),
                ]);
            }

            $voucher = $session->username ?: $session->voucher;
            $password = $session->password;
            if ($password === null || $password === '') {
                throw new RuntimeException('Stored hotspot session has no password.');
            }

            $timeout = (string) max(60, (int) now()->diffInSeconds($session->expires_at, true));

            if (! $radius->createUser($voucher, $password, $tier, $timeout)) {
                throw new RuntimeException('RADIUS rejected hotspot user provisioning.');
            }

            if (! $mikrotik->ensureHotspotUser($branch, $voucher, $tier->mikrotikRateLimit())) {
                throw new RuntimeException('Router rejected hotspot user provisioning.');
            }

            $session->forceFill(['provisioned_at' => now()])->save();
            $selection?->update(['voucher' => $voucher]);

            event(new WifiIssued($session->fresh() ?? $session));
        } catch (\Throwable $e) {
            Log::warning('Hotspot provision failed (degraded)', [
                'reservation' => $reservation->confirmation_number,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
