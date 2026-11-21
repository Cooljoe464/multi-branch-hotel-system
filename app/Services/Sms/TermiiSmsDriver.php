<?php

namespace App\Services\Sms;

use App\Models\Branch;
use App\Services\Security\SecretManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Termii Strictly-Only SMS Gateway Driver.
 *
 * Exclusively provides SMS messaging with Nigeria DND bypass route (channel: 'dnd')
 * for transactional reservation OTPs, digital door lock PINs, and check-in links.
 */
class TermiiSmsDriver
{
    public const SECRET_API_KEY = 'termii.api_key';

    public const SECRET_SENDER_ID = 'termii.sender_id';

    public const DEFAULT_ENDPOINT = 'https://api.ng.termii.com/api';

    public function __construct(private ?SecretManager $secrets = null)
    {
        $this->secrets = $secrets ?? app(SecretManager::class);
    }

    /**
     * Send an SMS message using Termii.
     *
     * @return array{ok: bool, message_id: ?string, balance: ?float, error: ?string}
     */
    public function send(string $to, string $message, ?Branch $branch = null, string $channel = 'dnd'): array
    {
        $apiKey = $this->apiKey($branch);
        $senderId = $this->senderId($branch);
        $endpoint = rtrim(config('services.termii.endpoint', self::DEFAULT_ENDPOINT), '/');

        if (! $apiKey) {
            Log::warning('Termii SMS requested but no API key configured.');

            return [
                'ok' => false,
                'message_id' => null,
                'balance' => null,
                'error' => 'Termii API key is not configured.',
            ];
        }

        // Clean Nigerian phone number to international format (234...)
        $formattedTo = $this->formatPhoneNumber($to);

        $payload = [
            'to' => $formattedTo,
            'from' => $senderId,
            'sms' => $message,
            'type' => 'plain',
            'channel' => in_array($channel, ['dnd', 'WhatsApp', 'generic'], true) ? $channel : 'dnd',
            'api_key' => $apiKey,
        ];

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->post("{$endpoint}/sms/send", $payload);

            if ($response->successful()) {
                $data = $response->json();
                $messageId = (string) ($data['message_id'] ?? $data['message_id_str'] ?? uniqid('termii_'));
                $balance = isset($data['balance']) ? (float) $data['balance'] : null;

                return [
                    'ok' => true,
                    'message_id' => $messageId,
                    'balance' => $balance,
                    'error' => null,
                ];
            }

            $errMsg = $response->json('message') ?? 'Termii returned status '.$response->status();
            Log::warning('Termii SMS dispatch failed', [
                'to' => $formattedTo,
                'status' => $response->status(),
                'error' => $errMsg,
            ]);

            return [
                'ok' => false,
                'message_id' => null,
                'balance' => null,
                'error' => $errMsg,
            ];
        } catch (\Throwable $e) {
            Log::error('Termii SMS exception: '.$e->getMessage(), ['to' => $formattedTo]);

            return [
                'ok' => false,
                'message_id' => null,
                'balance' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check current Termii account wallet balance.
     *
     * @return array{ok: bool, balance: float, currency: string, error: ?string}
     */
    public function getBalance(?Branch $branch = null): array
    {
        $apiKey = $this->apiKey($branch);
        $endpoint = rtrim(config('services.termii.endpoint', self::DEFAULT_ENDPOINT), '/');

        if (! $apiKey) {
            return ['ok' => false, 'balance' => 0.0, 'currency' => 'NGN', 'error' => 'API key missing'];
        }

        try {
            $response = Http::timeout(10)->get("{$endpoint}/get-balance", [
                'api_key' => $apiKey,
            ]);

            if ($response->successful()) {
                return [
                    'ok' => true,
                    'balance' => (float) ($response->json('balance') ?? 0.0),
                    'currency' => (string) ($response->json('currency') ?? 'NGN'),
                    'error' => null,
                ];
            }

            return ['ok' => false, 'balance' => 0.0, 'currency' => 'NGN', 'error' => 'HTTP '.$response->status()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'balance' => 0.0, 'currency' => 'NGN', 'error' => $e->getMessage()];
        }
    }

    public function apiKey(?Branch $branch): ?string
    {
        return $this->secrets->get($branch, self::SECRET_API_KEY)
            ?? config('services.termii.api_key');
    }

    public function senderId(?Branch $branch): string
    {
        return $this->secrets->get($branch, self::SECRET_SENDER_ID)
            ?? config('services.termii.sender_id', 'Hospitality');
    }

    public function formatPhoneNumber(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';

        // If local format e.g. 08031234567, replace leading 0 with 234
        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            return '234'.substr($digits, 1);
        }

        // If already international e.g. 2348031234567
        if (str_starts_with($digits, '234') && strlen($digits) === 13) {
            return $digits;
        }

        return $digits;
    }
}
