<?php

namespace App\Console\Commands;

use App\Jobs\DeprovisionWifiJob;
use App\Models\WifiSession;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hotspot:expire-sessions')]
#[Description('Mark past-expiry wifi sessions deprovisioned and queue NAS disconnects')]
class HotspotExpireSessions extends Command
{
    public function handle(): int
    {
        $expired = WifiSession::whereNull('deprovisioned_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $session) {
            $session->update([
                'revoked_at' => $session->revoked_at ?? now(),
                'deprovisioned_at' => now(),
            ]);

            if ($session->reservation_id !== null) {
                DeprovisionWifiJob::dispatch($session->reservation_id);
            }
        }

        $this->info("Expired {$expired->count()} wifi session(s).");

        return self::SUCCESS;
    }
}
