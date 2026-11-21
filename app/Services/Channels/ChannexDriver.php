<?php

namespace App\Services\Channels;

use App\Contracts\ChannelDriver;
use App\Models\Branch;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Channex REST OTA Gateway Driver.
 *
 * Ultra-fast JSON API for channel distribution and reservation webhooks.
 */
class ChannexDriver implements ChannelDriver
{
    public const DEFAULT_ENDPOINT = 'https://api.channex.io/api/v1';

    public function name(): string
    {
        return 'channex';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, external_ref: string|null, error: string|null}
     */
    public function pushAri(string $idempotencyKey, array $payload): array
    {
        $endpoint = config('services.channex.endpoint', self::DEFAULT_ENDPOINT);
        $apiKey = config('services.channex.api_key', 'test-channex-key');

        try {
            $response = Http::withHeaders([
                'user-api-key' => $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(15)->post("{$endpoint}/restrictions", [
                'values' => $payload['restrictions'] ?? [$payload],
            ]);

            if ($response->successful()) {
                $ref = $response->json('meta.request_id') ?? 'chx-'.$idempotencyKey;

                return ['ok' => true, 'external_ref' => (string) $ref, 'error' => null];
            }

            return [
                'ok' => false,
                'external_ref' => null,
                'error' => $response->json('errors.0.title') ?? 'Channex returned HTTP '.$response->status(),
            ];
        } catch (\Throwable $e) {
            Log::warning('Channex pushAri failed: '.$e->getMessage(), ['key' => $idempotencyKey]);

            return ['ok' => false, 'external_ref' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return list<array{stay_date: string, channel_room_code: string, sellable: int, rate_minor: int|null}>
     */
    public function fetchInventory(Branch $branch, string $from, string $to): array
    {
        $endpoint = config('services.channex.endpoint', self::DEFAULT_ENDPOINT);
        $apiKey = config('services.channex.api_key', 'test-channex-key');

        try {
            $response = Http::withHeaders([
                'user-api-key' => $apiKey,
            ])->timeout(15)->get("{$endpoint}/availability", [
                'start_date' => $from,
                'end_date' => $to,
            ]);

            if ($response->successful()) {
                $items = $response->json('data') ?? [];

                return is_array($items) ? $items : [];
            }
        } catch (\Throwable $e) {
            Log::warning('Channex fetchInventory failed: '.$e->getMessage());
        }

        return [];
    }
}
