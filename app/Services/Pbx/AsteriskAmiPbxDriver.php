<?php

namespace App\Services\Pbx;

use App\Contracts\PbxDriver;
use App\Models\Branch;
use App\Services\Security\SecretManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Asterisk / FreePBX AMI & CDR Driver.
 *
 * Interfaces with Asterisk CDR HTTP API or local AMI bridge daemon.
 */
class AsteriskAmiPbxDriver implements PbxDriver
{
    public const SECRET_API_KEY = 'pbx.asterisk.api_key';

    public const SECRET_ENDPOINT = 'pbx.asterisk.endpoint';

    public function __construct(private ?SecretManager $secrets = null)
    {
        $this->secrets = $secrets ?? app(SecretManager::class);
    }

    public function name(): string
    {
        return 'asterisk';
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
                $req = $req->withHeaders(['Authorization' => 'Bearer '.$apiKey]);
            }

            $response = $req->get("{$endpoint}/cdr", [
                'start_time' => $since->format('Y-m-d H:i:s'),
            ]);

            if ($response->successful()) {
                $rows = $response->json('cdr') ?? $response->json('data') ?? [];
                $results = [];

                foreach ($rows as $row) {
                    $results[] = [
                        'cdr_id' => (string) ($row['uniqueid'] ?? $row['cdr_id'] ?? uniqid('ast_')),
                        'extension' => (string) ($row['src'] ?? $row['extension'] ?? ''),
                        'destination' => (string) ($row['dst'] ?? $row['destination'] ?? ''),
                        'duration_secs' => (int) ($row['billsec'] ?? $row['duration'] ?? 0),
                        'reservation_id' => isset($row['reservation_id']) ? (int) $row['reservation_id'] : null,
                    ];
                }

                return $results;
            }
        } catch (\Throwable $e) {
            Log::warning('Asterisk CDR poll failed: '.$e->getMessage());
        }

        return [];
    }

    public function apiKey(Branch $branch): ?string
    {
        return $this->secrets->get($branch, self::SECRET_API_KEY)
            ?? config('services.pbx.asterisk.api_key');
    }

    public function endpoint(Branch $branch): ?string
    {
        return $this->secrets->get($branch, self::SECRET_ENDPOINT)
            ?? config('services.pbx.asterisk.endpoint');
    }
}
