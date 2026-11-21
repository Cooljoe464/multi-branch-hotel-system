<?php

namespace App\Services\Pbx;

use App\Contracts\PbxDriver;
use App\Models\Branch;
use App\Services\Security\SecretManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 3CX Cloud Telephony PBX Driver.
 *
 * Communicates with 3CX Call Control API & Call Detail Record (CDR) logs.
 */
class ThreeCxPbxDriver implements PbxDriver
{
    public const SECRET_API_KEY = 'pbx.3cx.api_key';

    public const SECRET_ENDPOINT = 'pbx.3cx.endpoint';

    public function __construct(private ?SecretManager $secrets = null)
    {
        $this->secrets = $secrets ?? app(SecretManager::class);
    }

    public function name(): string
    {
        return '3cx';
    }

    public function isConfigured(Branch $branch): bool
    {
        return $this->apiKey($branch) !== null && $this->endpoint($branch) !== null;
    }

    /**
     * @return list<array{cdr_id: string, extension: string, destination: string, duration_secs: int, reservation_id: int|null}>
     */
    public function poll(Branch $branch, Carbon $since): array
    {
        if (! $this->isConfigured($branch)) {
            return [];
        }

        $endpoint = rtrim($this->endpoint($branch), '/');
        $apiKey = $this->apiKey($branch);

        try {
            $response = Http::withHeaders([
                'X-API-KEY' => $apiKey,
                'Accept' => 'application/json',
            ])->timeout(15)->get("{$endpoint}/api/v1/cdr", [
                'since' => $since->toIso8601String(),
            ]);

            if ($response->successful()) {
                $rawRecords = $response->json('records') ?? $response->json('data') ?? [];
                $normalized = [];

                foreach ($rawRecords as $record) {
                    $normalized[] = [
                        'cdr_id' => (string) ($record['id'] ?? $record['call_id'] ?? uniqid('3cx_')),
                        'extension' => (string) ($record['caller'] ?? $record['extension'] ?? ''),
                        'destination' => (string) ($record['destination'] ?? $record['callee'] ?? ''),
                        'duration_secs' => (int) ($record['duration'] ?? $record['talking_duration'] ?? 0),
                        'reservation_id' => isset($record['reservation_id']) ? (int) $record['reservation_id'] : null,
                    ];
                }

                return $normalized;
            }

            Log::warning('3CX CDR poll failed: HTTP '.$response->status());
        } catch (\Throwable $e) {
            Log::warning('3CX CDR poll exception: '.$e->getMessage());
        }

        return [];
    }

    public function apiKey(Branch $branch): ?string
    {
        return $this->secrets->get($branch, self::SECRET_API_KEY)
            ?? config('services.pbx.3cx.api_key');
    }

    public function endpoint(Branch $branch): ?string
    {
        return $this->secrets->get($branch, self::SECRET_ENDPOINT)
            ?? config('services.pbx.3cx.endpoint');
    }
}
