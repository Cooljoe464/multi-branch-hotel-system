<?php

namespace App\Services\Pbx;

use App\Contracts\PbxDriver;
use App\Models\Branch;
use App\Services\Security\SecretManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Grandstream UCM & Yeastar PBX Gateway Driver.
 *
 * Supports Grandstream UCM6300/UCM6200 HTTP CDR reporting and Yeastar S-Series integration.
 */
class GrandstreamUcmPbxDriver implements PbxDriver
{
    public const SECRET_API_KEY = 'pbx.grandstream.api_key';

    public const SECRET_ENDPOINT = 'pbx.grandstream.endpoint';

    public function __construct(private ?SecretManager $secrets = null)
    {
        $this->secrets = $secrets ?? app(SecretManager::class);
    }

    public function name(): string
    {
        return 'grandstream';
    }

    public function isConfigured(Branch $branch): bool
    {
        return $this->endpoint($branch) !== null;
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
            $req = Http::acceptJson()->timeout(15);
            if ($apiKey) {
                $req = $req->withHeaders(['Authorization' => 'Token '.$apiKey]);
            }

            $response = $req->get("{$endpoint}/api/v1/cdrs", [
                'from_time' => $since->toIso8601String(),
            ]);

            if ($response->successful()) {
                $rawList = $response->json('cdrs') ?? $response->json('data') ?? [];
                $out = [];

                foreach ($rawList as $cdr) {
                    $out[] = [
                        'cdr_id' => (string) ($cdr['acctid'] ?? $cdr['session_id'] ?? uniqid('gs_')),
                        'extension' => (string) ($cdr['src'] ?? $cdr['caller'] ?? ''),
                        'destination' => (string) ($cdr['dst'] ?? $cdr['called'] ?? ''),
                        'duration_secs' => (int) ($cdr['billsec'] ?? $cdr['talk_time'] ?? 0),
                        'reservation_id' => isset($cdr['reservation_id']) ? (int) $cdr['reservation_id'] : null,
                    ];
                }

                return $out;
            }
        } catch (\Throwable $e) {
            Log::warning('Grandstream UCM poll error: '.$e->getMessage());
        }

        return [];
    }

    public function apiKey(Branch $branch): ?string
    {
        return $this->secrets->get($branch, self::SECRET_API_KEY)
            ?? config('services.pbx.grandstream.api_key');
    }

    public function endpoint(Branch $branch): ?string
    {
        return $this->secrets->get($branch, self::SECRET_ENDPOINT)
            ?? config('services.pbx.grandstream.endpoint');
    }
}
