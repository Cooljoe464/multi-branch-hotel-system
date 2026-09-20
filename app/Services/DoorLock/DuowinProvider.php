<?php

namespace App\Services\DoorLock;

use App\Contracts\LockProvider;
use App\Models\DoorLockAuditLog;
use App\Models\DoorLockGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DuowinProvider implements LockProvider
{
    public function issueKey(DoorLockGateway $gateway, DoorLockAuditLog $log): bool
    {
        $payload = [
            'room_number' => $log->room->number,
            'guest_name' => $log->reservation->guest_name ?? 'Guest',
            'pin_code' => $log->pin_code,
            'valid_from' => $log->valid_from->toIso8601String(),
            'valid_until' => $log->valid_until->toIso8601String(),
            'card_type' => $gateway->settings['card_type'] ?? 'rfid',
            'access_level' => $gateway->settings['access_level'] ?? 'guest',
        ];

        $log->update(['payload' => ['request' => $payload]]);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$gateway->api_key,
                'Content-Type' => 'application/json',
            ])->timeout(15)->post($gateway->api_base_url.'/encode', $payload);

            if ($response->successful()) {
                /** @var array<string, mixed> $data */
                $data = $response->json() ?? [];
                $log->update([
                    'payload' => array_merge($log->payload ?? [], ['response' => $data]),
                ]);

                $credentialId = is_string($data['card_id'] ?? null)
                    ? (string) $data['card_id']
                    : (is_string($data['id'] ?? null) ? (string) $data['id'] : '');

                return $log->markSuccess($credentialId);
            }

            Log::warning('Duowin API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return $log->markFailed('API returned status '.$response->status());
        } catch (\Throwable $e) {
            Log::error('Duowin API exception', ['error' => $e->getMessage()]);

            return $log->markFailed($e->getMessage());
        }
    }

    public function revokeKey(DoorLockGateway $gateway, DoorLockAuditLog $log): bool
    {
        if (! $log->credential_id) {
            $log->markFailed('No credential ID to revoke');

            return false;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$gateway->api_key,
                'Content-Type' => 'application/json',
            ])->timeout(15)->post($gateway->api_base_url.'/revoke', [
                'card_id' => $log->credential_id,
                'room_number' => $log->room->number,
            ]);

            if ($response->successful()) {
                $log->update(['status' => 'revoked']);

                return true;
            }

            return $log->markFailed('Revoke returned status '.$response->status());
        } catch (\Throwable $e) {
            Log::error('Duowin revoke exception', ['error' => $e->getMessage()]);

            return $log->markFailed($e->getMessage());
        }
    }

    public function extendKey(DoorLockGateway $gateway, DoorLockAuditLog $log): bool
    {
        if (! $log->credential_id) {
            $log->markFailed('No credential ID to extend');

            return false;
        }

        $payload = [
            'card_id' => $log->credential_id,
            'valid_until' => $log->valid_until->toIso8601String(),
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$gateway->api_key,
                'Content-Type' => 'application/json',
            ])->timeout(15)->post($gateway->api_base_url.'/extend', $payload);

            if ($response->successful()) {
                $log->update(['payload' => array_merge($log->payload ?? [], ['extended' => true])]);

                return true;
            }

            return $log->markFailed('Extend returned status '.$response->status());
        } catch (\Throwable $e) {
            Log::error('Duowin extend exception', ['error' => $e->getMessage()]);

            return $log->markFailed($e->getMessage());
        }
    }

    public function getProviderName(): string
    {
        return 'duowin';
    }
}
