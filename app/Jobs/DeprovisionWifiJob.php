<?php

namespace App\Jobs;

use App\Models\Reservation;
use App\Models\WifiSession;
use App\Services\MikrotikService;
use App\Services\RadiusService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DeprovisionWifiJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $reservationId)
    {
        $queue = config('hotspot.queue', 'network');
        $this->onQueue(is_string($queue) ? $queue : 'network');
    }

    public function handle(RadiusService $radius, MikrotikService $mikrotik): void
    {
        $reservation = Reservation::with(['branch'])->find($this->reservationId);

        if (! $reservation) {
            return;
        }

        $sessions = WifiSession::where('reservation_id', $reservation->id)
            ->whereNull('deprovisioned_at')
            ->get();

        foreach ($sessions as $session) {
            try {
                $username = $session->username ?? $session->voucher;
                $radius->disableUser($username);
                $radius->disconnectUser($username);
                $mikrotik->kickActive($session->branch, $username);

                $session->update([
                    'revoked_at' => $session->revoked_at ?? now(),
                    'deprovisioned_at' => now(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Hotspot deprovision failed (degraded)', [
                    'session' => $session->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
