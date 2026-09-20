<?php

namespace App\Services\DoorLock;

use App\Contracts\LockProvider;
use App\Models\DoorLockAuditLog;
use App\Models\DoorLockGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AssaAbloyProvider implements LockProvider
{
    public function issueKey(DoorLockGateway $gateway, DoorLockAuditLog $log): bool
    {
        $payload = [
            'encoderId' => $log->room->number,
            'guestName' => $log->reservation->guest_name ?? 'Guest',
            'pinCode' => $this->generatePin(),
            'validFrom' => $log->valid_from->toIso8601String(),
            'validUntil' => $log->valid_until->toIso8601String(),
            'accessLevel' => $gateway->settings['access_level'] ?? 'guest',
        ];

        $log->update(['payload' => ['request' => $payload]]);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$gateway->api_key,
                'Content-Type' => 'application/json',
            ])->timeout(15)->post($gateway->api_base_url.'/v1/credentials', $payload);

            if ($response->successful()) {
                /** @var array<string, mixed> $data */
                $data = $response->json() ?? [];
                $log->update([
                    'payload' => array_merge($log->payload ?? [], ['response' => $data]),
                ]);

                $credentialId = is_string($data['credential_id'] ?? null)
                    ? (string) $data['credential_id']
                    : (is_string($data['id'] ?? null) ? (string) $data['id'] : '');

                return $log->markSuccess($credentialId);
            }

            Log::warning('ASSA ABLOY API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return $log->markFailed('API returned status '.$response->status());
        } catch (\Throwable $e) {
            Log::error('ASSA ABLOY API exception', ['error' => $e->getMessage()]);

            return $log->markFailed($e->getMessage());
        }
    }

    public function revokeKey(DoorLockGateway $gateway, DoorLockAuditLog $log): bool
    {
        if (! $log->credential_id) {
            return $log->markFailed('No credential ID to revoke');
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$gateway->api_key,
                'Content-Type' => 'application/json',
            ])->timeout(15)->delete($gateway->api_base_url.'/v1/credentials/'.$log->credential_id);

            if ($response->successful()) {
                $log->update(['status' => 'revoked']);

                return true;
            }

            return $log->markFailed('Revoke returned status '.$response->status());
        } catch (\Throwable $e) {
            Log::error('ASSA ABLOY revoke exception', ['error' => $e->getMessage()]);

            return $log->markFailed($e->getMessage());
        }
    }

    public function extendKey(DoorLockGateway $gateway, DoorLockAuditLog $log): bool
    {
        if (! $log->credential_id) {
            return $log->markFailed('No credential ID to extend');
        }

        $payload = [
            'validUntil' => $log->valid_until->toIso8601String(),
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$gateway->api_key,
                'Content-Type' => 'application/json',
            ])->timeout(15)->put($gateway->api_base_url.'/v1/credentials/'.$log->credential_id, $payload);

            if ($response->successful()) {
                $log->update(['payload' => array_merge($log->payload ?? [], ['extended' => true])]);

                return true;
            }

            return $log->markFailed('Extend returned status '.$response->status());
        } catch (\Throwable $e) {
            Log::error('ASSA ABLOY extend exception', ['error' => $e->getMessage()]);

            return $log->markFailed($e->getMessage());
        }
    }

    public function getProviderName(): string
    {
        return 'assa_abloy';
    }

    private function generatePin(): string
    {
        return str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    }
}
