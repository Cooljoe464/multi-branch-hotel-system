<?php

namespace App\Contracts;

use App\Models\Branch;

/**
 * OTA transport. Implementations perform transport only; the outbox,
 * retry and reconciliation state machines live in ChannelService.
 * Production adapters (Booking.com, Expedia) arrive with OTA sandbox
 * certification; until then the log/failing drivers stand in.
 */
interface ChannelDriver
{
    public function name(): string;

    /**
     * Push one ARI payload. The idempotency key is stable across
     * retries and replays: the OTA must apply it at most once.
     * Both result fields are always present (null when not applicable)
     * so callers never branch on shape.
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, external_ref: string|null, error: string|null}
     */
    public function pushAri(string $idempotencyKey, array $payload): array;

    /**
     * Pull OTA-side availability for a stay-date range.
     *
     * @return list<array{stay_date: string, channel_room_code: string, sellable: int, rate_minor: int|null}>
     */
    public function fetchInventory(Branch $branch, string $from, string $to): array;
}
