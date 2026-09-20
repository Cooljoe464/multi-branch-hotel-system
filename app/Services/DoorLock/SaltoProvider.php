<?php

namespace App\Services\DoorLock;

use App\Contracts\LockProvider;
use App\Models\DoorLockAuditLog;
use App\Models\DoorLockGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SaltoProvider implements LockProvider
{
    public function issueKey(DoorLockGateway $gateway, DoorLockAuditLog $log): bool
    {
        $payload = [
            'roomId' => $log->room->number,
            'guestName' => $log->reservation->guest_name ?? 'Guest',
            'startDate' => $log->valid_from->format('Y-m-d\TH:i:s'),
            'endDate' => $log->valid_until->format('Y-m-d\TH:i:s'),
            'lockType' => $gateway->settings['lock_type'] ?? 'standard',
        ];

        $log->update(['payload' => ['request' => $payload]]);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$gateway->api_key,
                'Content-Type' => 'application/json',
            ])->timeout(15)->post($gateway->api_base_url.'/api/v2/accesses', $payload);

            if ($response->successful()) {
                /** @var array<string, mixed> $data */
                $data = $response->json() ?? [];
                $log->update([
                    'payload' => array_merge($log->payload ?? [], ['response' => $data]),
                ]);

                $credentialId = is_string($data['access_id'] ?? null)
                    ? (string) $data['access_id']
                    : (is_string($data['id'] ?? null) ? (string) $data['id'] : '');

                return $log->markSuccess($credentialId);
            }

            Log::warning('Salto API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return $log->markFailed('API returned status '.$response->status());
        } catch (\Throwable $e) {
            Log::error('Salto API exception', ['error' => $e->getMessage()]);

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
            ])->timeout(15)->delete($gateway->api_base_url.'/api/v2/accesses/'.$log->credential_id);

            if ($response->successful()) {
                $log->update(['status' => 'revoked']);

                return true;
            }

            return $log->markFailed('Revoke returned status '.$response->status());
        } catch (\Throwable $e) {
            Log::error('Salto revoke exception', ['error' => $e->getMessage()]);

            return $log->markFailed($e->getMessage());
        }
    }

    public function extendKey(DoorLockGateway $gateway, DoorLockAuditLog $log): bool
    {
        if (! $log->credential_id) {
            return $log->markFailed('No credential ID to extend');
        }

        $payload = [
            'endDate' => $log->valid_until->format('Y-m-d\TH:i:s'),
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$gateway->api_key,
                'Content-Type' => 'application/json',
            ])->timeout(15)->put($gateway->api_base_url.'/api/v2/accesses/'.$log->credential_id, $payload);

            if ($response->successful()) {
                $log->update(['payload' => array_merge($log->payload ?? [], ['extended' => true])]);

                return true;
            }

            return $log->markFailed('Extend returned status '.$response->status());
        } catch (\Throwable $e) {
            Log::error('Salto extend exception', ['error' => $e->getMessage()]);

            return $log->markFailed($e->getMessage());
        }
    }

    public function getProviderName(): string
    {
        return 'salto';
    }
}
