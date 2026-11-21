<?php

namespace App\Services\DoorLock;

use App\Contracts\LockProvider;
use App\Models\DoorLockAuditLog;
use App\Models\DoorLockGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * BtLock / Adel MF1 DLL Card Encoder Driver.
 *
 * Interfaces with btlock57L.dll / btmf1usb.dll encoders via local hardware bridge.
 */
class BtLockProvider implements LockProvider
{
    public function getProviderName(): string
    {
        return 'btlock';
    }

    public function issueKey(DoorLockGateway $gateway, DoorLockAuditLog $log): bool
    {
        if (($gateway->settings['manual_mode'] ?? false) === true) {
            return $log->markManual('btlock-manual-'.$log->id);
        }

        $payload = [
            'action' => 'WriteCard',
            'room' => $log->room->number,
            'building' => $gateway->settings['building'] ?? '01',
            'tower' => $gateway->settings['tower'] ?? '01',
            'door' => $log->room->number,
            'stime' => $log->valid_from->format('ymdHi'),
            'etime' => $log->valid_until->format('ymdHi'),
            'cardpass' => $gateway->settings['card_pass'] ?? 'FFFFFFFFFFFF',
        ];

        return $this->dispatchToBridge($gateway, $log, $payload, 'issue');
    }

    public function revokeKey(DoorLockGateway $gateway, DoorLockAuditLog $log): bool
    {
        if (($gateway->settings['manual_mode'] ?? false) === true) {
            return $log->markRevoked();
        }

        $payload = [
            'action' => 'ClearCard',
            'cardpass' => $gateway->settings['card_pass'] ?? 'FFFFFFFFFFFF',
        ];

        return $this->dispatchToBridge($gateway, $log, $payload, 'revoke');
    }

    public function extendKey(DoorLockGateway $gateway, DoorLockAuditLog $log): bool
    {
        if (($gateway->settings['manual_mode'] ?? false) === true) {
            return $log->markSuccess($log->credential_id ?? 'btlock-manual-'.$log->id);
        }

        $payload = [
            'action' => 'ExtendCard',
            'room' => $log->room->number,
            'etime' => $log->valid_until->format('ymdHi'),
            'cardpass' => $gateway->settings['card_pass'] ?? 'FFFFFFFFFFFF',
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
            ])->timeout(10)->post("{$baseUrl}/btlock/card", $payload);

            if ($response->successful() && ($response->json('result') === 0 || $response->json('status') === 'ok')) {
                $cardId = $response->json('card_no') ?? 'BTL-'.uniqid();

                if ($action === 'revoke') {
                    return $log->markRevoked();
                }

                return $log->markSuccess((string) $cardId);
            }

            $error = $response->json('error') ?? 'BtLock operation failed with HTTP '.$response->status();

            return $log->markFailed($error);
        } catch (\Throwable $e) {
            Log::warning('BtLock bridge dispatch error: '.$e->getMessage());

            return $log->markFailed($e->getMessage());
        }
    }
}
