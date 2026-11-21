<?php

namespace App\Services\Channels;

use App\Contracts\ChannelDriver;
use App\Models\Branch;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudbeds Connect OTA Gateway Driver.
 *
 * Synchronizes ARI updates with Cloudbeds PMS/Channel Manager.
 */
class CloudbedsDriver implements ChannelDriver
{
    public const DEFAULT_ENDPOINT = 'https://api.cloudbeds.com/api/v1.2';

    public function name(): string
    {
        return 'cloudbeds';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, external_ref: string|null, error: string|null}
     */
    public function pushAri(string $idempotencyKey, array $payload): array
    {
        $endpoint = config('services.cloudbeds.endpoint', self::DEFAULT_ENDPOINT);
        $token = config('services.cloudbeds.access_token', 'test-cloudbeds-token');

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
                'X-Request-ID' => $idempotencyKey,
                'Content-Type' => 'application/json',
            ])->timeout(15)->post("{$endpoint}/postInventory", [
                'idempotency_key' => $idempotencyKey,
                'rates_and_inventory' => $payload,
            ]);

            if ($response->successful()) {
                $ref = $response->json('message_id') ?? 'cb-'.$idempotencyKey;

                return ['ok' => true, 'external_ref' => (string) $ref, 'error' => null];
            }

            return [
                'ok' => false,
                'external_ref' => null,
                'error' => $response->json('message') ?? 'Cloudbeds returned HTTP '.$response->status(),
            ];
        } catch (\Throwable $e) {
            Log::warning('Cloudbeds pushAri failed: '.$e->getMessage(), ['key' => $idempotencyKey]);

            return ['ok' => false, 'external_ref' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return list<array{stay_date: string, channel_room_code: string, sellable: int, rate_minor: int|null}>
     */
    public function fetchInventory(Branch $branch, string $from, string $to): array
    {
        $endpoint = config('services.cloudbeds.endpoint', self::DEFAULT_ENDPOINT);
        $token = config('services.cloudbeds.access_token', 'test-cloudbeds-token');

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
            ])->timeout(15)->get("{$endpoint}/getInventory", [
                'startDate' => $from,
                'endDate' => $to,
            ]);

            if ($response->successful()) {
                $items = $response->json('data') ?? [];

                return is_array($items) ? $items : [];
            }
        } catch (\Throwable $e) {
            Log::warning('Cloudbeds fetchInventory failed: '.$e->getMessage());
        }

        return [];
    }
}
