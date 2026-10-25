<?php

namespace App\Services\DoorLock;

use App\Contracts\LockProvider;
use App\Models\DoorLockAuditLog;
use App\Models\DoorLockGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * dormakaba keycards over the standard Community/Oracode REST shape:
 * a client-credentials token, then access codes created, extended and
 * cancelled against {base}/access_codes. Credentials resolve through
 * the branch secret store (door_lock.{id}.client_id / .api_secret) so
 * the gateway row itself never carries the secret.
 */
class DormakabaProvider implements LockProvider
{
    public function issueKey(DoorLockGateway $gateway, DoorLockAuditLog $log): bool
    {
        $token = $this->accessToken($gateway);

        if ($token === null) {
            return $log->markFailed('dormakaba client credentials are not configured.');
        }

        $payload = [
            'room_number' => $log->room->number,
            'guest_name' => $log->reservation->guest_name ?? 'Guest',
            'credential_type' => $gateway->settings['credential_type'] ?? 'keycard',
            'starts_at' => $log->valid_from->toIso8601String(),
            'ends_at' => $log->valid_until->toIso8601String(),
            'code' => $log->pin_code,
        ];

        // The access code itself never lands in the audit trail.
        $log->update(['payload' => ['request' => array_diff_key($payload, ['code' => true])]]);

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->timeout(15)
                ->post(rtrim($gateway->api_base_url, '/').'/access_codes', $payload);

            if ($response->successful()) {
                /** @var array<string, mixed> $data */
                $data = $response->json() ?? [];
                $log->update([
                    'payload' => array_merge($log->payload ?? [], ['response' => $data]),
                ]);

                return $log->markSuccess($this->credentialId($data));
            }

            Log::warning('dormakaba API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return $log->markFailed('API returned status '.$response->status());
        } catch (\Throwable $e) {
            Log::error('dormakaba API exception', ['error' => $e->getMessage()]);

            return $log->markFailed($e->getMessage());
        }
    }

    public function revokeKey(DoorLockGateway $gateway, DoorLockAuditLog $log): bool
    {
        if (! $log->credential_id) {
            return $log->markFailed('No credential ID to revoke');
        }

        $token = $this->accessToken($gateway);

        if ($token === null) {
            return $log->markFailed('dormakaba client credentials are not configured.');
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(15)
                ->delete(rtrim($gateway->api_base_url, '/').'/access_codes/'.$log->credential_id);

            if ($response->successful()) {
                $log->update(['status' => 'revoked']);

                return true;
            }

            return $log->markFailed('Revoke returned status '.$response->status());
        } catch (\Throwable $e) {
            Log::error('dormakaba revoke exception', ['error' => $e->getMessage()]);

            return $log->markFailed($e->getMessage());
        }
    }

    public function extendKey(DoorLockGateway $gateway, DoorLockAuditLog $log): bool
    {
        if (! $log->credential_id) {
            return $log->markFailed('No credential ID to extend');
        }

        $token = $this->accessToken($gateway);

        if ($token === null) {
            return $log->markFailed('dormakaba client credentials are not configured.');
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->timeout(15)
                ->patch(rtrim($gateway->api_base_url, '/').'/access_codes/'.$log->credential_id, [
                    'ends_at' => $log->valid_until->toIso8601String(),
                ]);

            if ($response->successful()) {
                $log->update(['payload' => array_merge($log->payload ?? [], ['extended' => true])]);

                return true;
            }

            return $log->markFailed('Extend returned status '.$response->status());
        } catch (\Throwable $e) {
            Log::error('dormakaba extend exception', ['error' => $e->getMessage()]);

            return $log->markFailed($e->getMessage());
        }
    }

    public function getProviderName(): string
    {
        return 'dormakaba';
    }

    /**
     * Client-credentials token, cached per gateway until just before the
     * vendor expires it — one token covers issue/extend/revoke traffic.
     */
    private function accessToken(DoorLockGateway $gateway): ?string
    {
        $clientId = $gateway->clientId();
        $clientSecret = $gateway->apiSecret();

        if ($clientId === null || $clientSecret === null) {
            return null;
        }

        $cached = Cache::get($this->tokenKey($gateway));

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::acceptJson()->asJson()->timeout(15)
            ->post(rtrim($gateway->api_base_url, '/').'/oauth/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);

        if (! $response->successful()) {
            Log::warning('dormakaba token error', ['status' => $response->status()]);

            return null;
        }

        $token = $this->stringOrNull($response->json('access_token'));

        if ($token === null) {
            return null;
        }

        $expiresIn = $response->json('expires_in');
        $ttl = (is_int($expiresIn) || is_numeric($expiresIn)) ? max(60, (int) $expiresIn - 30) : 3600;

        Cache::put($this->tokenKey($gateway), $token, now()->addSeconds($ttl));

        return $token;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function credentialId(array $data): string
    {
        foreach (['access_code_id', 'id', 'access_code'] as $candidate) {
            $value = $data[$candidate] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }

            if (is_array($value)) {
                $nested = $value['id'] ?? null;

                if (is_string($nested) && $nested !== '') {
                    return $nested;
                }
            }
        }

        return '';
    }

    private function tokenKey(DoorLockGateway $gateway): string
    {
        return 'door_lock.token.'.$gateway->id;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
