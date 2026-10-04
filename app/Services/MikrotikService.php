<?php

namespace App\Services;

use App\Models\Branch;
use Illuminate\Support\Facades\Log;

/**
 * Single RouterOS per branch, reached over the WireGuard tunnel.
 * V1 is log-backed (fake) so provisioning never blocks check-in when
 * the tunnel is down; swap in a RouterOS API client later behind
 * `hotspot.mikrotik_fake=false` without changing callers.
 */
class MikrotikService
{
    public function ensureHotspotUser(Branch $branch, string $username, string $rateLimit): bool
    {
        Log::info('MikroTik ensure hotspot user', [
            'branch' => $branch->id,
            'username' => $username,
            'rate' => $rateLimit,
            'fake' => $this->isFake(),
        ]);

        return true;
    }

    public function kickActive(Branch $branch, string $username): bool
    {
        Log::info('MikroTik kick active user', [
            'branch' => $branch->id,
            'username' => $username,
            'fake' => $this->isFake(),
        ]);

        return true;
    }

    public function nasReachable(Branch $branch): bool
    {
        // Real check would ping the tunnel IP / hit the API login endpoint.
        // V1 reports configured-ness so the dashboard can show intent.
        $settings = is_array($branch->settings) ? $branch->settings : [];

        return isset($settings['tunnel_ip']) || isset($settings['radius_host']);
    }

    private function isFake(): bool
    {
        return (bool) config('hotspot.mikrotik_fake', true);
    }
}
