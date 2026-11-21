<?php

namespace App\Services\DoorLock;

use App\Contracts\LockProvider;
use App\Models\DoorLockAuditLog;
use App\Models\DoorLockGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fidelio Interface Application Specification (FIAS) Lock Protocol Driver.
 *
 * Implements the standard PMS-to-Lock Server TCP protocol (as specified in FIAS.ini / FIAS1.ini)
 * for lock vendors like TGLock, Adel, VingCard, and Kaba.
 *
 * Messages are framed by STX (\x02) and ETX (\x03).
 * - KR: Key Request (new card issue / extend)
 * - KO: Key Out (checkout / revoke)
 * - KD: Key Delete (room move / clear)
 * - KA: Key Answer (lock server response)
 */
class FiasLockDriver implements LockProvider
{
    public const STX = "\x02";

    public const ETX = "\x03";

    public function getProviderName(): string
    {
        return 'fias';
    }

    public function issueKey(DoorLockGateway $gateway, DoorLockAuditLog $log): bool
    {
        if (($gateway->settings['manual_mode'] ?? false) === true) {
            return $log->markManual('fias-manual-'.$log->id);
        }

        $room = $log->room;
        $guestName = $log->reservation?->guest_name ?? 'Guest';
        $resId = (string) ($log->reservation_id ?? $log->id);

        $fields = [
            'RN' => $room->number,
            'G#' => $resId,
            'GN' => $guestName,
            'DA' => $log->valid_from->format('ymd'),
            'TI' => $log->valid_from->format('His'),
            'DD' => $log->valid_until->format('ymd'),
            'TO' => $log->valid_until->format('His'),
            'KT' => '1', // 1 = New Key
        ];

        $frame = $this->buildFrame('KR', $fields);
        $response = $this->sendFiasMessage($gateway, $frame);

        if ($response['ok']) {
            $credId = $response['data']['K#'] ?? 'fias-card-'.$resId.'-'.$room->number;

            return $log->markSuccess((string) $credId);
        }

        return $log->markFailed($response['error'] ?? 'FIAS lock server rejected key request');
    }

    public function revokeKey(DoorLockGateway $gateway, DoorLockAuditLog $log): bool
    {
        if (($gateway->settings['manual_mode'] ?? false) === true) {
            return $log->markRevoked();
        }

        $fields = [
            'RN' => $log->room->number,
            'G#' => (string) ($log->reservation_id ?? $log->id),
        ];

        $frame = $this->buildFrame('KO', $fields);
        $response = $this->sendFiasMessage($gateway, $frame);

        if ($response['ok']) {
            return $log->markRevoked();
        }

        return $log->markFailed($response['error'] ?? 'FIAS lock server failed to revoke key');
    }

    public function extendKey(DoorLockGateway $gateway, DoorLockAuditLog $log): bool
    {
        if (($gateway->settings['manual_mode'] ?? false) === true) {
            return $log->markSuccess($log->credential_id ?? 'fias-manual-'.$log->id);
        }

        $fields = [
            'RN' => $log->room->number,
            'G#' => (string) ($log->reservation_id ?? $log->id),
            'DD' => $log->valid_until->format('ymd'),
            'TO' => $log->valid_until->format('His'),
            'KT' => '3', // 3 = Key Extension
        ];

        $frame = $this->buildFrame('KR', $fields);
        $response = $this->sendFiasMessage($gateway, $frame);

        if ($response['ok']) {
            $credId = $response['data']['K#'] ?? $log->credential_id ?? 'fias-card-ext';

            return $log->markSuccess((string) $credId);
        }

        return $log->markFailed($response['error'] ?? 'FIAS lock server failed to extend key');
    }

    /**
     * Build standard FIAS framed string.
     *
     * @param  array<string, string>  $fields
     */
    public function buildFrame(string $recordType, array $fields): string
    {
        $body = $recordType;
        foreach ($fields as $key => $val) {
            $body .= '|'.$key.$val;
        }

        return self::STX.$body.self::ETX;
    }

    /**
     * Parse received FIAS message frame.
     *
     * @return array{record_type: string, fields: array<string, string>}
     */
    public function parseFrame(string $raw): array
    {
        $clean = trim($raw, self::STX.self::ETX."\r\n");
        $parts = explode('|', $clean);
        $recordType = array_shift($parts);

        $fields = [];
        foreach ($parts as $part) {
            if (strlen($part) >= 2) {
                $code = substr($part, 0, 2);
                $val = substr($part, 2);
                $fields[$code] = $val;
            }
        }

        return [
            'record_type' => $recordType,
            'fields' => $fields,
        ];
    }

    /**
     * Send message to the FIAS gateway endpoint (HTTP bridge or raw TCP).
     *
     * @return array{ok: bool, data: array<string, string>, error: ?string}
     */
    protected function sendFiasMessage(DoorLockGateway $gateway, string $frame): array
    {
        $baseUrl = rtrim($gateway->api_base_url, '/');

        // Check if gateway connects via HTTP bridge or TCP socket
        if (str_starts_with($baseUrl, 'http://') || str_starts_with($baseUrl, 'https://')) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.$gateway->apiKey(),
                    'Content-Type' => 'application/json',
                ])->timeout(10)->post("{$baseUrl}/fias/send", [
                    'frame' => base64_encode($frame),
                ]);

                if ($response->successful()) {
                    $rawAnswer = base64_decode($response->json('answer') ?? '') ?: '';
                    $parsed = $this->parseFrame($rawAnswer);

                    // Check if answer status is OK (AS = OK or 0)
                    $as = $parsed['fields']['AS'] ?? 'OK';
                    $isOk = in_array(strtoupper($as), ['OK', '0', 'SUCCESS'], true);

                    return [
                        'ok' => $isOk,
                        'data' => $parsed['fields'],
                        'error' => $isOk ? null : 'FIAS server returned error status: '.$as,
                    ];
                }

                return ['ok' => false, 'data' => [], 'error' => 'Bridge returned HTTP '.$response->status()];
            } catch (\Throwable $e) {
                Log::warning('FIAS bridge request failed: '.$e->getMessage());

                return ['ok' => false, 'data' => [], 'error' => $e->getMessage()];
            }
        }

        // Direct TCP socket mode (e.g. "tcp://192.168.1.100:5010" or "192.168.1.100:5010")
        return $this->sendRawTcp($baseUrl, $frame);
    }

    /**
     * @return array{ok: bool, data: array<string, string>, error: ?string}
     */
    protected function sendRawTcp(string $address, string $frame): array
    {
        $target = str_replace('tcp://', '', $address);
        $parts = explode(':', $target);
        $host = $parts[0] ?? '127.0.0.1';
        $port = isset($parts[1]) ? (int) $parts[1] : 5010;

        $socket = @fsockopen($host, $port, $errno, $errstr, 5);
        if (! $socket) {
            return ['ok' => false, 'data' => [], 'error' => "Cannot connect to FIAS server {$host}:{$port} ({$errstr})"];
        }

        fwrite($socket, $frame);
        stream_set_timeout($socket, 5);
        $responseRaw = fread($socket, 2048);
        fclose($socket);

        if (! $responseRaw) {
            return ['ok' => false, 'data' => [], 'error' => 'No response from FIAS lock server'];
        }

        $parsed = $this->parseFrame($responseRaw);
        $as = $parsed['fields']['AS'] ?? 'OK';
        $isOk = in_array(strtoupper($as), ['OK', '0', 'SUCCESS'], true);

        return [
            'ok' => $isOk,
            'data' => $parsed['fields'],
            'error' => $isOk ? null : 'FIAS returned error code: '.$as,
        ];
    }
}
