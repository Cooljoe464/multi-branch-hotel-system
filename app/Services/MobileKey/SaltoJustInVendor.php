<?php

namespace App\Services\MobileKey;

use App\Models\MobileKey;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Salto JustIN Mobile Key Cloud Client.
 *
 * Provisions OTA BLE mobile digital keys via Salto KS / Space JustIN API.
 */
class SaltoJustInVendor implements MobileKeyVendor
{
    public const DEFAULT_ENDPOINT = 'https://api.saltosystems.com/justin/v1';

    public function provision(MobileKey $key): string
    {
        $endpoint = config('services.mobile_key.salto.endpoint', self::DEFAULT_ENDPOINT);
        $apiKey = config('services.mobile_key.salto.api_key', 'test-salto-key');

        $reservation = $key->reservation;
        $doorId = $reservation?->room?->number ?? 'ROOM-0';

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(15)->post("{$endpoint}/keys", [
                'phone_uuid' => $key->device_id,
                'door' => $doorId,
                'start' => $key->valid_from->timestamp,
                'end' => $key->valid_to->timestamp,
            ]);

            if ($response->successful()) {
                return (string) ($response->json('key_id') ?? 'salto-'.$key->id);
            }

            throw new \RuntimeException('Salto JustIN returned error HTTP '.$response->status());
        } catch (\Throwable $e) {
            Log::warning('Salto JustIN provision failed: '.$e->getMessage(), ['key_id' => $key->id]);
            throw $e;
        }
    }

    public function revoke(MobileKey $key): void
    {
        $endpoint = config('services.mobile_key.salto.endpoint', self::DEFAULT_ENDPOINT);
        $apiKey = config('services.mobile_key.salto.api_key', 'test-salto-key');

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
            ])->timeout(15)->delete("{$endpoint}/keys/{$key->device_id}");

            if (! $response->successful()) {
                Log::warning('Salto JustIN revoke warning HTTP '.$response->status());
            }
        } catch (\Throwable $e) {
            Log::warning('Salto JustIN revoke failed: '.$e->getMessage());
            throw $e;
        }
    }
}
