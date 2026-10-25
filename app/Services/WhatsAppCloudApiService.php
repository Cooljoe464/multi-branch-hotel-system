<?php

namespace App\Services;

use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Services\Security\SecretManager;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Direct Meta WhatsApp Cloud API client. Each branch carries its own
 * WABA credentials in branches.settings.whatsapp (non-secret flags) and
 * branch_secrets (tokens), so phone numbers and access tokens stay
 * isolated per property.
 */
class WhatsAppCloudApiService
{
    public const SETTINGS_KEY = 'whatsapp';

    public const SECRET_ACCESS_TOKEN = 'whatsapp.access_token';

    public const SECRET_APP_SECRET = 'whatsapp.app_secret';

    private SecretManager $secrets;

    public function __construct(?SecretManager $secrets = null)
    {
        $this->secrets = $secrets ?? app(SecretManager::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(Branch $branch): array
    {
        $settings = $branch->settings;

        if (! is_array($settings)) {
            return [];
        }

        $whatsapp = $settings[self::SETTINGS_KEY] ?? null;

        return is_array($whatsapp) ? $this->stringKeyed($whatsapp) : [];
    }

    public function isEnabled(Branch $branch): bool
    {
        return ($this->settings($branch)['enabled'] ?? false) === true;
    }

    public function isConfigured(Branch $branch): bool
    {
        $settings = $this->settings($branch);

        return $this->stringValue($settings['phone_number_id'] ?? null) !== null
            && $this->accessToken($branch) !== null;
    }

    public function phoneNumberId(Branch $branch): ?string
    {
        return $this->stringValue($this->settings($branch)['phone_number_id'] ?? null);
    }

    public function wabaId(Branch $branch): ?string
    {
        return $this->stringValue($this->settings($branch)['waba_id'] ?? null);
    }

    public function verifyToken(Branch $branch): ?string
    {
        return $this->stringValue($this->settings($branch)['verify_token'] ?? null);
    }

    public function accessToken(Branch $branch): ?string
    {
        return $this->secrets->get($branch, self::SECRET_ACCESS_TOKEN)
            ?? $this->decryptSecret($this->settings($branch)['access_token_enc'] ?? null);
    }

    public function appSecret(Branch $branch): ?string
    {
        return $this->secrets->get($branch, self::SECRET_APP_SECRET)
            ?? $this->decryptSecret($this->settings($branch)['app_secret_enc'] ?? null);
    }

    /**
     * Persist the WABA token in the branch secret store. A null
     * value clears it (manual rotation / deprovisioning).
     */
    public function storeAccessToken(Branch $branch, ?string $token, bool $rotate = false): void
    {
        $this->store($branch, self::SECRET_ACCESS_TOKEN, $token, $rotate);
    }

    public function storeAppSecret(Branch $branch, ?string $secret, bool $rotate = false): void
    {
        $this->store($branch, self::SECRET_APP_SECRET, $secret, $rotate);
    }

    public function verifySignature(Branch $branch, string $rawBody, ?string $signature): bool
    {
        $secret = $this->appSecret($branch);

        if ($secret === null || $signature === null || $signature === '') {
            return false;
        }

        $provided = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;
        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $provided);
    }

    public function findBranchByPhoneNumberId(string $phoneNumberId): ?Branch
    {
        return Branch::query()
            ->where('settings->whatsapp->phone_number_id', $phoneNumberId)
            ->first();
    }

    public function findBranchByVerifyToken(string $token): ?Branch
    {
        return Branch::query()
            ->where('settings->whatsapp->verify_token', $token)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function sendText(Branch $branch, string $to, string $body): array
    {
        return $this->send($branch, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $body],
        ]);
    }

    /**
     * @param  array<int, string>  $parameters
     * @return array<string, mixed>
     */
    public function sendTemplate(Branch $branch, string $to, string $template, array $parameters = [], string $language = 'en'): array
    {
        $components = [];

        if ($parameters !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(fn (string $value) => ['type' => 'text', 'text' => $value], $parameters),
            ];
        }

        return $this->send($branch, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => $language],
                'components' => $components,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(Branch $branch, array $payload): array
    {
        $phoneNumberId = $this->phoneNumberId($branch);
        $token = $this->accessToken($branch);

        if ($phoneNumberId === null || $token === null) {
            throw new AvailabilityException('WHATSAPP_NOT_CONFIGURED', 'WhatsApp Cloud API credentials are not configured for this property.');
        }

        $version = config('services.whatsapp.graph_version', 'v20.0');
        $version = is_string($version) && $version !== '' ? $version : 'v20.0';

        $response = $this->client($token)->post(
            "https://graph.facebook.com/{$version}/{$phoneNumberId}/messages",
            $payload,
        );

        if ($response->failed()) {
            Log::warning('WhatsApp Cloud API send failed.', [
                'branch_id' => $branch->id,
                'status' => $response->status(),
                'body' => $response->json('error.message'),
            ]);

            throw new AvailabilityException('WHATSAPP_SEND_FAILED', 'WhatsApp rejected the outbound message.');
        }

        $json = $response->json();

        return is_array($json) ? $this->stringKeyed($json) : [];
    }

    private function client(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout(10);
    }

    private function store(Branch $branch, string $key, ?string $value, bool $rotate): void
    {
        if ($value === null) {
            $this->secrets->forget($branch, $key);

            return;
        }

        if ($rotate) {
            $this->secrets->rotate($branch, $key, $value);

            return;
        }

        $this->secrets->set($branch, $key, $value);
    }

    private function decryptSecret(mixed $value): ?string
    {
        $value = $this->stringValue($value);

        if ($value === null) {
            return null;
        }

        if (! str_starts_with($value, 'enc:')) {
            return $value;
        }

        try {
            return Crypt::decryptString(substr($value, 4));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>
     */
    private function stringKeyed(array $value): array
    {
        $normalized = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $normalized[$key] = $item;
            }
        }

        return $normalized;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
