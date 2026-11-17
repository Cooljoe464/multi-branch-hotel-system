<?php

namespace App\Services;

/**
 * Timestamped webhook signatures (spec 4.3): HMAC-SHA256 over "timestamp.body",
 * carried as "t=<unix>,v1=<hex>". A receiver rejects anything outside the
 * tolerance window, and accepts a signature made with any currently valid
 * secret so a rotation overlap never drops deliveries.
 */
class WebhookSignature
{
    public const HEADER = 'X-Webhook-Signature-V2';

    public const TOLERANCE_SECONDS = 300;

    public static function header(string $body, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * @param  list<string>  $secrets  every secret valid right now (current, plus previous during overlap)
     */
    public static function verify(
        string $header,
        string $body,
        array $secrets,
        int $toleranceSeconds = self::TOLERANCE_SECONDS,
        ?int $now = null,
    ): bool {
        $now ??= time();

        if (! preg_match('/^t=(\d{1,12}),v1=([a-f0-9]{64})$/', $header, $matches)) {
            return false;
        }

        $timestamp = (int) $matches[1];

        if (abs($now - $timestamp) > $toleranceSeconds) {
            return false;
        }

        foreach ($secrets as $secret) {
            if (hash_equals(hash_hmac('sha256', $timestamp.'.'.$body, $secret), $matches[2])) {
                return true;
            }
        }

        return false;
    }
}
