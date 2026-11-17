<?php

namespace App\Services\DoorLock;

use App\Contracts\LockProvider;
use App\Models\DoorLockAuditLog;
use App\Models\DoorLockGateway;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;

class TTLockProvider implements LockProvider
{
    public function issueKey(DoorLockGateway $gateway, DoorLockAuditLog $log): bool
    {
        if (($gateway->settings['manual_mode'] ?? false) === true) {
            return $log->markManual('manual-'.$log->id);
        }

        $credentials = $this->credentials($gateway);
        if ($credentials === null) {
            return $log->markFailed('TTLock client ID and access token must be configured.');
        }

        $payload = array_merge($credentials, [
            'lockId' => $gateway->settings['lock_id'] ?? null,
            'keyboardPwd' => $log->pin_code,
            'keyboardPwdName' => $log->reservation->guest_name ?? 'Guest',
            'startDate' => $this->milliseconds($log->valid_from),
            'endDate' => $this->milliseconds($log->valid_until),
            'addType' => 2,
        ]);

        if (! is_int($payload['lockId']) && ! is_string($payload['lockId'])) {
            return $log->markFailed('TTLock lock_id is not configured.');
        }

        $log->update(['payload' => ['request' => $payload]]);

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(15)
                ->post(rtrim($gateway->api_base_url, '/').'/v3/keyboardPwd/add', $payload);

            if (! $response->successful() || (($response->json('errcode') ?? 0) !== 0)) {
                return $log->markFailed('TTLock API returned status '.$response->status());
            }

            $credentialIdValue = $response->json('keyboardPwdId') ?? $response->json('data.keyboardPwdId') ?? $response->json('id');
            $credentialId = is_scalar($credentialIdValue) ? (string) $credentialIdValue : '';
            if ($credentialId === '') {
                return $log->markFailed('TTLock response did not contain a credential ID.');
            }

            $log->update(['payload' => array_merge($log->payload ?? [], ['response' => $response->json()])]);

            return $log->markSuccess($credentialId);
        } catch (\Throwable $exception) {
            return $log->markFailed($exception->getMessage());
        }
    }

    public function revokeKey(DoorLockGateway $gateway, DoorLockAuditLog $log): bool
    {
        if (! $log->credential_id) {
            return $log->markFailed('No credential ID to revoke.');
        }

        if (($gateway->settings['manual_mode'] ?? false) === true) {
            return $log->markManual($log->credential_id);
        }

        return $this->mutate($gateway, $log, '/v3/keyboardPwd/delete', [
            'lockId' => $gateway->settings['lock_id'] ?? '',
            'keyboardPwdId' => $log->credential_id,
            'deleteType' => 2,
        ], 'revoked');
    }

    public function extendKey(DoorLockGateway $gateway, DoorLockAuditLog $log): bool
    {
        if (! $log->credential_id) {
            return $log->markFailed('No credential ID to extend.');
        }

        if (($gateway->settings['manual_mode'] ?? false) === true) {
            return $log->markManual($log->credential_id);
        }

        return $this->mutate($gateway, $log, '/v3/keyboardPwd/change', [
            'lockId' => $gateway->settings['lock_id'] ?? '',
            'keyboardPwdId' => $log->credential_id,
            'startDate' => $this->milliseconds($log->valid_from),
            'endDate' => $this->milliseconds($log->valid_until),
            'changeType' => 2,
        ], 'success');
    }

    public function getProviderName(): string
    {
        return 'ttlock';
    }

    /** @param  array<string, mixed>  $payload */
    private function mutate(DoorLockGateway $gateway, DoorLockAuditLog $log, string $path, array $payload, string $successStatus): bool
    {
        $credentials = $this->credentials($gateway);
        if ($credentials === null) {
            return $log->markFailed('TTLock client ID and access token must be configured.');
        }

        if (! is_int($payload['lockId'] ?? null) && ! is_string($payload['lockId'] ?? null)) {
            return $log->markFailed('TTLock lock_id is not configured.');
        }

        $payload = array_merge($credentials, $payload, ['date' => now()->getTimestamp() * 1000]);

        try {
            $response = Http::asForm()->acceptJson()->timeout(15)->post(rtrim($gateway->api_base_url, '/').$path, $payload);
            if (! $response->successful() || (($response->json('errcode') ?? 0) !== 0)) {
                return $log->markFailed('TTLock API returned status '.$response->status());
            }

            $log->update(['status' => $successStatus, 'hardware_verified' => true, 'payload' => array_merge($log->payload ?? [], ['request' => $payload, 'response' => $response->json(), 'extended' => $successStatus === 'success'])]);

            return true;
        } catch (\Throwable $exception) {
            return $log->markFailed($exception->getMessage());
        }
    }

    private function milliseconds(CarbonInterface $date): int
    {
        return $date->getTimestamp() * 1000;
    }

    /** @return array{clientId: string, accessToken: string, date: int}|null */
    private function credentials(DoorLockGateway $gateway): ?array
    {
        $clientId = $gateway->clientId();
        $accessToken = $gateway->apiKey();

        if ($clientId === null || $accessToken === null) {
            return null;
        }

        return [
            'clientId' => $clientId,
            'accessToken' => $accessToken,
            'date' => now()->getTimestamp() * 1000,
        ];
    }
}
