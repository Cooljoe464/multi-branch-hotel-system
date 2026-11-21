<?php

namespace App\Services\Channels;

use App\Contracts\ChannelDriver;
use App\Models\Branch;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * RateGain Channel Manager Driver.
 *
 * Implements ARI updates via RateGain RezGain / DHIS messaging gateway.
 */
class RateGainDriver implements ChannelDriver
{
    public const DEFAULT_ENDPOINT = 'https://api.rategain.com/rezgain/v2';

    public function name(): string
    {
        return 'rategain';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, external_ref: string|null, error: string|null}
     */
    public function pushAri(string $idempotencyKey, array $payload): array
    {
        $endpoint = config('services.rategain.endpoint', self::DEFAULT_ENDPOINT);
        $apiKey = config('services.rategain.api_key', 'test-rategain-key');

        try {
            $response = Http::withHeaders([
                'X-API-KEY' => $apiKey,
                'X-Transaction-ID' => $idempotencyKey,
                'Content-Type' => 'application/json',
            ])->timeout(15)->post("{$endpoint}/inventory/sync", [
                'reference' => $idempotencyKey,
                'payload' => $payload,
            ]);

            if ($response->successful()) {
                $ref = $response->json('rezgain_id') ?? 'rg-'.$idempotencyKey;

                return ['ok' => true, 'external_ref' => (string) $ref, 'error' => null];
            }

            return [
                'ok' => false,
                'external_ref' => null,
                'error' => $response->json('error_message') ?? 'RateGain returned HTTP '.$response->status(),
            ];
        } catch (\Throwable $e) {
            Log::warning('RateGain pushAri failed: '.$e->getMessage(), ['key' => $idempotencyKey]);

            return ['ok' => false, 'external_ref' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return list<array{stay_date: string, channel_room_code: string, sellable: int, rate_minor: int|null}>
     */
    public function fetchInventory(Branch $branch, string $from, string $to): array
    {
        $endpoint = config('services.rategain.endpoint', self::DEFAULT_ENDPOINT);
        $apiKey = config('services.rategain.api_key', 'test-rategain-key');

        try {
            $response = Http::withHeaders([
                'X-API-KEY' => $apiKey,
            ])->timeout(15)->get("{$endpoint}/inventory/snapshot", [
                'branch_id' => $branch->id,
                'from_date' => $from,
                'to_date' => $to,
            ]);

            if ($response->successful()) {
                $items = $response->json('inventory') ?? [];

                return is_array($items) ? $items : [];
            }
        } catch (\Throwable $e) {
            Log::warning('RateGain fetchInventory failed: '.$e->getMessage());
        }

        return [];
    }
}
