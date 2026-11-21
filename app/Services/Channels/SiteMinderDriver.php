<?php

namespace App\Services\Channels;

use App\Contracts\ChannelDriver;
use App\Models\Branch;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SiteMinder Channel Manager Driver.
 *
 * Implements ARI (Availability, Rates & Inventory) push and two-way reservation sync
 * via SiteMinder's Channel Exchange / pmsXchange protocol.
 */
class SiteMinderDriver implements ChannelDriver
{
    public const DEFAULT_ENDPOINT = 'https://api.siteminder.com/pmsxchange/v1';

    public function name(): string
    {
        return 'siteminder';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, external_ref: string|null, error: string|null}
     */
    public function pushAri(string $idempotencyKey, array $payload): array
    {
        $endpoint = config('services.siteminder.endpoint', self::DEFAULT_ENDPOINT);
        $apiKey = config('services.siteminder.api_key', 'test-siteminder-key');
        $hotelCode = $payload['channel_hotel_code'] ?? 'SM-DEFAULT';

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'X-Hotel-Code' => $hotelCode,
                'X-Idempotency-Key' => $idempotencyKey,
                'Content-Type' => 'application/json',
            ])->timeout(15)->post("{$endpoint}/ari/updates", [
                'idempotency_key' => $idempotencyKey,
                'hotel_code' => $hotelCode,
                'updates' => $payload['updates'] ?? [$payload],
            ]);

            if ($response->successful()) {
                $ref = $response->json('transaction_id') ?? 'sm-'.$idempotencyKey;

                return ['ok' => true, 'external_ref' => (string) $ref, 'error' => null];
            }

            return [
                'ok' => false,
                'external_ref' => null,
                'error' => $response->json('message') ?? 'SiteMinder returned HTTP '.$response->status(),
            ];
        } catch (\Throwable $e) {
            Log::warning('SiteMinder ARI push failed: '.$e->getMessage(), ['key' => $idempotencyKey]);

            return ['ok' => false, 'external_ref' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return list<array{stay_date: string, channel_room_code: string, sellable: int, rate_minor: int|null}>
     */
    public function fetchInventory(Branch $branch, string $from, string $to): array
    {
        $endpoint = config('services.siteminder.endpoint', self::DEFAULT_ENDPOINT);
        $apiKey = config('services.siteminder.api_key', 'test-siteminder-key');

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(15)->get("{$endpoint}/inventory", [
                'branch_id' => $branch->id,
                'from' => $from,
                'to' => $to,
            ]);

            if ($response->successful()) {
                $items = $response->json('data') ?? [];

                return is_array($items) ? $items : [];
            }
        } catch (\Throwable $e) {
            Log::warning('SiteMinder fetchInventory failed: '.$e->getMessage());
        }

        return [];
    }
}
