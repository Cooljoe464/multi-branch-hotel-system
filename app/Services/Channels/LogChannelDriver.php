<?php

namespace App\Services\Channels;

use App\Contracts\ChannelDriver;
use App\Models\Branch;
use Illuminate\Support\Facades\Log;

/**
 * Stand-in OTA: records every push in memory (assertable in tests) and
 * always acks. Effective changes are keyed by idempotency key, so the
 * test suite can prove retries and replays never double-apply.
 */
class LogChannelDriver implements ChannelDriver
{
    /** @var array<string, list<array<string, mixed>>> */
    protected static array $sent = [];

    /** @var array<string, array<string, mixed>> */
    protected static array $effective = [];

    /** @var array<int, list<array{stay_date: string, channel_room_code: string, sellable: int, rate_minor: int|null}>> */
    protected static array $inventory = [];

    public function name(): string
    {
        return 'log';
    }

    public function pushAri(string $idempotencyKey, array $payload): array
    {
        self::$sent[$idempotencyKey][] = $payload;
        self::$effective[$idempotencyKey] = $payload;

        Log::info('Channel ARI pushed (log driver).', ['key' => $idempotencyKey]);

        return ['ok' => true, 'external_ref' => 'log-'.$idempotencyKey, 'error' => null];
    }

    public function fetchInventory(Branch $branch, string $from, string $to): array
    {
        return self::$inventory[$branch->id] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function sentFor(string $idempotencyKey): array
    {
        return self::$sent[$idempotencyKey] ?? [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function effective(): array
    {
        return self::$effective;
    }

    /**
     * @param  list<array{stay_date: string, channel_room_code: string, sellable: int, rate_minor: int|null}>  $rows
     */
    public static function stageInventory(int $branchId, array $rows): void
    {
        self::$inventory[$branchId] = $rows;
    }

    public static function flush(): void
    {
        self::$sent = [];
        self::$effective = [];
        self::$inventory = [];
    }
}
