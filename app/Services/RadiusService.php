<?php

namespace App\Services;

use App\Models\HotspotTier;
use Illuminate\Support\Facades\Log;

/**
 * Cloud FreeRADIUS sync. Laravel remains source of truth; this service
 * mirrors users into radcheck/radreply. Defaults to fake (log-only) so
 * branches work before the RADIUS DB link is provisioned.
 */
class RadiusService
{
    public function createUser(string $username, string $password, HotspotTier $tier, ?string $sessionTimeout = null): bool
    {
        if ($this->isFake()) {
            Log::info('RADIUS(fake) create user', [
                'username' => $username,
                'tier' => $tier->code,
                'rate' => $tier->mikrotikRateLimit(),
                'timeout' => $sessionTimeout,
            ]);

            return true;
        }

        // Real implementation: write radcheck (Cleartext-Password),
        // radreply (Mikrotik-Rate-Limit, Session-Timeout, WISPr-Bandwidth-*).
        // Kept behind the `radius.connection` until the DBA provisions it.
        Log::warning('RADIUS real mode not provisioned; falling back to fake', ['username' => $username]);

        return true;
    }

    public function disableUser(string $username): bool
    {
        if ($this->isFake()) {
            Log::info('RADIUS(fake) disable user', ['username' => $username]);

            return true;
        }

        Log::warning('RADIUS real mode not provisioned; falling back to fake', ['username' => $username]);

        return true;
    }

    public function disconnectUser(string $username, ?string $nasIp = null): bool
    {
        if ($this->isFake()) {
            Log::info('RADIUS(fake) CoA disconnect', ['username' => $username, 'nas' => $nasIp]);

            return true;
        }

        Log::warning('RADIUS CoA not provisioned; falling back to fake', ['username' => $username]);

        return true;
    }

    private function isFake(): bool
    {
        return (bool) config('radius.fake', true);
    }
}
