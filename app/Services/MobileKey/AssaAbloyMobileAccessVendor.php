<?php

namespace App\Services\MobileKey;

use App\Models\MobileKey;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Assa Abloy Mobile Access API Client.
 *
 * Communicates with Assa Abloy Hospitality Mobile Access Cloud Service (BLE / NFC digital keys).
 */
class AssaAbloyMobileAccessVendor implements MobileKeyVendor
{
    public const DEFAULT_ENDPOINT = 'https://api.assaabloyhospitality.com/mobile-access/v1';

    public function provision(MobileKey $key): string
    {
        $endpoint = config('services.mobile_key.assa_abloy.endpoint', self::DEFAULT_ENDPOINT);
        $apiKey = config('services.mobile_key.assa_abloy.api_key', 'test-assa-key');

        $reservation = $key->reservation;
        $roomNumber = $reservation?->room?->number ?? '000';

        try {
            $response = Http::withHeaders([
                'X-API-Key' => $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(15)->post("{$endpoint}/keys/issue", [
                'device_id' => $key->device_id,
                'room_number' => $roomNumber,
                'valid_from' => $key->valid_from->toIso8601String(),
                'valid_to' => $key->valid_to->toIso8601String(),
                'reservation_ref' => $reservation?->confirmation_number ?? 'RES-'.$key->reservation_id,
            ]);

            if ($response->successful()) {
                return (string) ($response->json('credential_id') ?? 'assa-'.$key->id);
            }

            throw new \RuntimeException('Assa Abloy returned error HTTP '.$response->status());
        } catch (\Throwable $e) {
            Log::warning('Assa Abloy mobile key provision failed: '.$e->getMessage(), ['key_id' => $key->id]);
            throw $e;
        }
    }

    public function revoke(MobileKey $key): void
    {
        $endpoint = config('services.mobile_key.assa_abloy.endpoint', self::DEFAULT_ENDPOINT);
        $apiKey = config('services.mobile_key.assa_abloy.api_key', 'test-assa-key');

        try {
            $response = Http::withHeaders([
                'X-API-Key' => $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(15)->post("{$endpoint}/keys/revoke", [
                'device_id' => $key->device_id,
                'reservation_ref' => $key->reservation?->confirmation_number ?? 'RES-'.$key->reservation_id,
            ]);

            if (! $response->successful()) {
                Log::warning('Assa Abloy mobile key revoke warning HTTP '.$response->status());
            }
        } catch (\Throwable $e) {
            Log::warning('Assa Abloy mobile key revoke failed: '.$e->getMessage());
            throw $e;
        }
    }
}
