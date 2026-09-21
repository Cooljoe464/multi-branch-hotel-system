<?php

namespace App\Services\Channels;

/**
 * Chaos stand-in: fails the first N pushes per idempotency key, then
 * delegates to the log driver. Proves the outbox retry/backoff path
 * without any network.
 */
class FailingChannelDriver extends LogChannelDriver
{
    protected static int $failuresBeforeAck = 2;

    /** @var array<string, int> */
    protected static array $failures = [];

    public function name(): string
    {
        return 'failing';
    }

    public function pushAri(string $idempotencyKey, array $payload): array
    {
        $failures = self::$failures[$idempotencyKey] ?? 0;

        if ($failures < self::$failuresBeforeAck) {
            self::$failures[$idempotencyKey] = $failures + 1;

            return ['ok' => false, 'external_ref' => null, 'error' => 'Simulated OTA outage.'];
        }

        return parent::pushAri($idempotencyKey, $payload);
    }

    public static function failTimes(int $times): void
    {
        self::$failuresBeforeAck = $times;
        self::$failures = [];
    }
}
