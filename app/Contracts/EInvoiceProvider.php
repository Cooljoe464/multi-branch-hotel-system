<?php

namespace App\Contracts;

use App\Models\Branch;
use App\Models\FiscalDocument;
use DateTimeInterface;

interface EInvoiceProvider
{
    public function provider(): string;

    /**
     * @return array{submit: bool, status: bool, credit_note: bool, reconcile: bool}
     */
    public function capabilities(Branch $branch): array;

    /**
     * @return array{ok: bool, submitted: bool, state: ?string, reference: ?string, irn: ?string, digital_stamp: ?string, qr_data: ?string, response_hash: ?string, cleared_at: ?string, error: ?string}
     */
    public function submit(FiscalDocument $document, string $idempotencyKey): array;

    /**
     * @return array{ok: bool, state: ?string, reference: ?string, digital_stamp: ?string, qr_data: ?string, response_hash: ?string, cleared_at: ?string, error: ?string}
     */
    public function status(Branch $branch, string $reference): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, submitted: bool, state: ?string, reference: ?string, irn: ?string, digital_stamp: ?string, qr_data: ?string, response_hash: ?string, cleared_at: ?string, error: ?string}
     */
    public function issueCreditNote(FiscalDocument $original, array $payload, string $idempotencyKey): array;

    /**
     * @return array{ok: bool, documents: list<array<string, mixed>>, error: ?string}
     */
    public function reconcile(Branch $branch, DateTimeInterface $since): array;
}
