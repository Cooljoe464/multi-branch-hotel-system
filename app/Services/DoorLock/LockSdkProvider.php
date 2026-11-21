<?php

namespace App\Services\DoorLock;

use App\Contracts\LockProvider;
use App\Models\DoorLockAuditLog;
use App\Models\DoorLockGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * LockSDK V4 / V5 Card Encoder Driver.
 *
 * Interfaces with LockSDK.dll / HSDApp.dll USB Mifare encoders via local hardware bridge.
 */
class LockSdkProvider implements LockProvider
{
    public function getProviderName(): string
    {
        return 'lock_sdk';
    }

    public function issueKey(DoorLockGateway $gateway, DoorLockAuditLog $log): bool
    {
        if (($gateway->settings['manual_mode'] ?? false) === true) {
            return $log->markManual('locksdk-manual-'.$log->id);
        }

        $payload = [
            'command' => 'write_card',
            'room_number' => $log->room->number,
            'card_type' => $gateway->settings['card_type'] ?? 'mifare_1k',
            'valid_from' => $log->valid_from->format('Y-m-d H:i:s'),
            'valid_until' => $log->valid_until->format('Y-m-d H:i:s'),
            'guest_name' => $log->reservation?->guest_name ?? 'Guest',
            'hotel_id' => $gateway->settings['hotel_id'] ?? '1',
            'sector' => (int) ($gateway->settings['card_sector'] ?? 4),
        ];

        return $this->dispatchToBridge($gateway, $log, $payload, 'issue');
    }

    public function revokeKey(DoorLockGateway $gateway, DoorLockAuditLog $log): bool
    {
        if (($gateway->settings['manual_mode'] ?? false) === true) {
            return $log->markRevoked();
        }

        $payload = [
            'command' => 'clear_card',
            'room_number' => $log->room->number,
            'card_id' => $log->credential_id,
        ];

        return $this->dispatchToBridge($gateway, $log, $payload, 'revoke');
    }

    public function extendKey(DoorLockGateway $gateway, DoorLockAuditLog $log): bool
    {
        if (($gateway->settings['manual_mode'] ?? false) === true) {
            return $log->markSuccess($log->credential_id ?? 'locksdk-manual-'.$log->id);
        }

        $payload = [
            'command' => 'extend_card',
            'room_number' => $log->room->number,
            'card_id' => $log->credential_id,
            'valid_until' => $log->valid_until->format('Y-m-d H:i:s'),
        ];

        return $this->dispatchToBridge($gateway, $log, $payload, 'extend');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function dispatchToBridge(DoorLockGateway $gateway, DoorLockAuditLog $log, array $payload, string $action): bool
    {
        $baseUrl = rtrim($gateway->api_base_url, '/');
        $apiKey = $gateway->apiKey();

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(10)->post("{$baseUrl}/locksdk/execute", $payload);

            if ($response->successful() && ($response->json('status') === 'success' || $response->json('code') === 0)) {
                $cardId = $response->json('card_sn') ?? $response->json('card_id') ?? 'SDK-'.uniqid();

                if ($action === 'revoke') {
                    return $log->markRevoked();
                }

                return $log->markSuccess((string) $cardId);
            }

            $error = $response->json('message') ?? 'Bridge returned error HTTP '.$response->status();

            return $log->markFailed($error);
        } catch (\Throwable $e) {
            Log::warning('LockSDK bridge dispatch error: '.$e->getMessage());

            return $log->markFailed($e->getMessage());
        }
    }
}
