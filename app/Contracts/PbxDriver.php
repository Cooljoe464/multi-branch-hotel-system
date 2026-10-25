<?php

namespace App\Contracts;

use App\Models\Branch;
use Illuminate\Support\Carbon;

/**
 * Seam for a real PBX/telephony vendor integration (3CX, Asterisk ARI,
 * Grandstream, …).
 *
 * Billing itself never talks to the vendor: CDRs arrive either through
 * the signed webhook (PbxService::ingest, the primary path) or through
 * poll() for PBXs that expose no webhook, and both feed the same
 * idempotent cdr_id → folio pipeline. Swapping in a vendor therefore
 * means adding one driver here — no changes to rating, posting or the
 * folio window.
 */
interface PbxDriver
{
    /**
     * Config key for this driver ('null', '3cx', 'asterisk', …).
     */
    public function name(): string;

    /**
     * Whether this branch has vendor credentials configured. A false
     * answer keeps call billing degraded (webhook-only or off) instead
     * of erroring.
     */
    public function isConfigured(Branch $branch): bool;

    /**
     * Pull CDRs emitted since the given moment (poll mode, for vendors
     * without webhooks). Each entry uses the shape PbxService::ingest
     * expects, and every cdr_id must be unique per vendor account so
     * re-polling stays idempotent.
     *
     * @return list<array{cdr_id: string, extension: string, destination: string, duration_secs: int, reservation_id: int|null}>
     */
    public function poll(Branch $branch, Carbon $since): array;
}
