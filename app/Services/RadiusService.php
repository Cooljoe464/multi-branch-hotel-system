<?php

namespace App\Services;

use App\Contracts\RadiusCoaTransport;
use App\Models\HotspotTier;
use App\Models\WifiSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FreeRADIUS SQL provisioning and RADIUS CoA transport.
 *
 * The SQL schema is the standard rlm_sql radcheck/radreply/radacct schema.
 * Fake mode remains explicit for local development; live mode fails closed
 * when the RADIUS database or shared secret is unavailable.
 */
class RadiusService
{
    private const BYTES_PER_GIGAWORD = 4_294_967_296;

    public function __construct(private readonly RadiusCoaTransport $coaTransport) {}

    public function createUser(string $username, string $password, HotspotTier $tier, ?string $sessionTimeout = null): bool
    {
        if ($this->isFake()) {
            Log::info('RADIUS(fake) create user', ['username' => $username, 'tier' => $tier->code]);

            return true;
        }

        try {
            $connection = DB::connection(config()->string('radius.connection'));
            $connection->table('radcheck')->where('username', $username)->delete();
            $connection->table('radreply')->where('username', $username)->delete();
            $connection->table('radcheck')->insert([
                ['username' => $username, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => $password],
            ]);
            $replies = [
                ['username' => $username, 'attribute' => 'Mikrotik-Rate-Limit', 'op' => ':=', 'value' => $tier->mikrotikRateLimit()],
            ];

            if ($sessionTimeout !== null) {
                $replies[] = ['username' => $username, 'attribute' => 'Session-Timeout', 'op' => ':=', 'value' => $sessionTimeout];
            }

            $connection->table('radreply')->insert($replies);

            return true;
        } catch (\Throwable $exception) {
            Log::error('RADIUS SQL provisioning failed.', ['username' => $username, 'error' => $exception->getMessage()]);

            return false;
        }
    }

    public function disableUser(string $username): bool
    {
        if ($this->isFake()) {
            Log::info('RADIUS(fake) disable user', ['username' => $username]);

            return true;
        }

        try {
            $connection = DB::connection(config()->string('radius.connection'));
            $connection->table('radcheck')->where('username', $username)->delete();
            $connection->table('radcheck')->insert([
                'username' => $username,
                'attribute' => 'Auth-Type',
                'op' => ':=',
                'value' => 'Reject',
            ]);

            return true;
        } catch (\Throwable $exception) {
            Log::error('RADIUS user disable failed.', ['username' => $username, 'error' => $exception->getMessage()]);

            return false;
        }
    }

    public function disconnectUser(string $username, ?string $nasIp = null): bool
    {
        if ($this->isFake()) {
            Log::info('RADIUS(fake) CoA disconnect', ['username' => $username, 'nas' => $nasIp]);

            return true;
        }

        $secret = config()->string('radius.shared_secret');
        $destination = $nasIp ?: config()->string('radius.host');

        if ($secret === '' || $destination === '') {
            return $this->disableUser($username);
        }

        if ($this->coaTransport->disconnect(
            $destination,
            config()->integer('radius.coa_port'),
            $secret,
            $username,
            config()->integer('radius.timeout_secs'),
            config()->integer('radius.retries'),
        )) {
            return true;
        }

        Log::warning('RADIUS CoA failed; falling back to SQL re-authentication.', ['username' => $username, 'nas' => $destination]);

        return $this->disableUser($username);
    }

    /** @param array<string, scalar|null> $attributes */
    public function ingestAccounting(array $attributes): bool
    {
        if ($this->isFake()) {
            return true;
        }

        $uniqueId = $attributes['acctuniqueid'] ?? null;
        $sessionId = $attributes['acctsessionid'] ?? null;
        $username = $attributes['username'] ?? null;

        if (! is_string($uniqueId) || trim($uniqueId) === ''
            || ! is_string($sessionId) || trim($sessionId) === ''
            || ! is_string($username) || trim($username) === '') {
            Log::warning('RADIUS accounting row rejected because its session identity is incomplete.');

            return false;
        }

        $allowedAttributes = [
            'acctuniqueid', 'acctsessionid', 'username', 'nasipaddress', 'nasportid',
            'acctstarttime', 'acctupdatetime', 'acctstoptime', 'acctinputoctets',
            'acctoutputoctets', 'acctsessiontime', 'acctterminatecause', 'callingstationid',
            'calledstationid', 'servicetype', 'framedipaddress',
        ];
        $accountingRow = array_intersect_key($attributes, array_flip($allowedAttributes));

        try {
            DB::connection(config()->string('radius.connection'))->table('radacct')->updateOrInsert(
                ['acctuniqueid' => trim($uniqueId)],
                $accountingRow,
            );

            return true;
        } catch (\Throwable $exception) {
            Log::error('RADIUS accounting ingestion failed.', ['error' => $exception->getMessage()]);

            return false;
        }
    }

    public function syncAccounting(): int
    {
        if ($this->isFake()) {
            return 0;
        }

        try {
            $rows = DB::connection(config()->string('radius.connection'))
                ->table('radacct')
                ->select([
                    'username',
                    'acctinputoctets',
                    'acctoutputoctets',
                    'acctinputgigawords',
                    'acctoutputgigawords',
                    'acctupdatetime',
                    'acctstoptime',
                ])
                ->whereNotNull('username')
                ->orderByDesc('acctupdatetime')
                ->get();
            $updated = 0;

            foreach ($rows as $row) {
                $username = is_string($row->username ?? null) ? $row->username : null;
                if ($username === null || $username === '') {
                    continue;
                }

                $session = WifiSession::query()->where('username', $username)->first();
                if ($session === null) {
                    continue;
                }

                $inputBytes = self::accountingBytes($row->acctinputoctets ?? null, $row->acctinputgigawords ?? null);
                $outputBytes = self::accountingBytes($row->acctoutputoctets ?? null, $row->acctoutputgigawords ?? null);
                $bytes = $inputBytes > PHP_INT_MAX - $outputBytes ? PHP_INT_MAX : $inputBytes + $outputBytes;
                $lastSeen = is_string($row->acctstoptime ?? null) && $row->acctstoptime !== ''
                    ? Carbon::parse($row->acctstoptime)
                    : (is_string($row->acctupdatetime ?? null) && $row->acctupdatetime !== '' ? Carbon::parse($row->acctupdatetime) : now());

                $session->update([
                    'bytes_used' => max($session->bytes_used, $bytes),
                    'last_acct_at' => $session->last_acct_at === null || $lastSeen->greaterThan($session->last_acct_at)
                        ? $lastSeen
                        : $session->last_acct_at,
                ]);
                $updated++;
            }

            return $updated;
        } catch (\Throwable $exception) {
            Log::error('RADIUS accounting sync failed.', ['error' => $exception->getMessage()]);

            return 0;
        }
    }

    public static function accountingBytes(mixed $octets, mixed $gigawords): int
    {
        $octetCount = self::nonNegativeInt($octets);
        $gigawordCount = self::nonNegativeInt($gigawords);

        if ($gigawordCount > intdiv(PHP_INT_MAX - $octetCount, self::BYTES_PER_GIGAWORD)) {
            return PHP_INT_MAX;
        }

        return $gigawordCount * self::BYTES_PER_GIGAWORD + $octetCount;
    }

    private static function nonNegativeInt(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private function isFake(): bool
    {
        return (bool) config('radius.fake', true);
    }
}
