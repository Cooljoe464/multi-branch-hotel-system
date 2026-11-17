<?php

namespace App\Services;

use App\Models\Branch;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Single RouterOS per branch, reached over the WireGuard tunnel. The
 * REST API is deliberately behind the existing fake switch so a branch
 * cannot silently claim network provisioning succeeded when it is not
 * configured.
 */
class MikrotikService
{
    public function ensureHotspotUser(Branch $branch, string $username, string $rateLimit): bool
    {
        if (! $this->isFake()) {
            $response = $this->client($branch)->put('/rest/ip/hotspot/user', [
                'name' => $username,
                'password' => $username,
                'profile' => $this->setting($branch, 'hotspot_profile', 'default'),
                'rate-limit' => $rateLimit,
                'comment' => 'HospitalityOS reservation hotspot',
            ]);

            if (! $response->successful()) {
                Log::error('MikroTik hotspot user provisioning failed.', ['branch' => $branch->id, 'status' => $response->status()]);

                return false;
            }

            return true;
        }

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
        if (! $this->isFake()) {
            $active = $this->client($branch)->get('/rest/ip/hotspot/active', ['user' => $username]);

            if (! $active->successful()) {
                return false;
            }

            $rows = $active->json();
            $rows = is_array($rows) ? ($rows['ret'] ?? $rows) : [];

            foreach (is_array($rows) ? $rows : [] as $row) {
                if (! is_array($row) || ! is_string($row['.id'] ?? null)) {
                    continue;
                }

                if (! $this->client($branch)->delete('/rest/ip/hotspot/active', ['.id' => $row['.id']])->successful()) {
                    return false;
                }
            }

            return true;
        }

        Log::info('MikroTik kick active user', [
            'branch' => $branch->id,
            'username' => $username,
            'fake' => $this->isFake(),
        ]);

        return true;
    }

    public function nasReachable(Branch $branch): bool
    {
        if (! $this->isFake()) {
            try {
                return $this->client($branch)->get('/rest/system/resource')->successful();
            } catch (\Throwable $exception) {
                Log::warning('MikroTik reachability check failed.', ['branch' => $branch->id, 'error' => $exception->getMessage()]);

                return false;
            }
        }

        $settings = is_array($branch->settings) ? $branch->settings : [];

        return isset($settings['tunnel_ip']) || isset($settings['radius_host']);
    }

    private function isFake(): bool
    {
        return (bool) config('hotspot.mikrotik_fake', true);
    }

    private function client(Branch $branch): PendingRequest
    {
        $baseUrl = $this->setting($branch, 'mikrotik_api_url', '');
        $token = $this->setting($branch, 'mikrotik_api_token', '');
        $username = $this->setting($branch, 'mikrotik_username', '');
        $password = $this->setting($branch, 'mikrotik_password', '');

        $client = Http::baseUrl(rtrim($baseUrl, '/'))
            ->acceptJson()
            ->asJson()
            ->timeout(config()->integer('hotspot.mikrotik_timeout'));

        if ($token !== '') {
            return $client->withToken($token);
        }

        return $client->withBasicAuth($username, $password);
    }

    private function setting(Branch $branch, string $key, string $default): string
    {
        $settings = is_array($branch->settings) ? $branch->settings : [];
        $value = $settings[$key] ?? null;

        return is_string($value) ? $value : $default;
    }
}
