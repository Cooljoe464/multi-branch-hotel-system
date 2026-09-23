<?php

namespace App\Services\Accounting;

use App\Models\AccountingLink;

/**
 * In-memory driver for tests and local development. Batches keyed
 * by external reference dedupe exactly like the provider side must.
 */
class FakeAccountingDriver implements AccountingExporter
{
    /**
     * @var array<string, array{link_id: int, business_date: string, batch: array<string, mixed>}>
     */
    private static array $batches = [];

    public static function reset(): void
    {
        self::$batches = [];
    }

    /**
     * @return array<string, array{link_id: int, business_date: string, batch: array<string, mixed>}>
     */
    public static function batches(): array
    {
        return self::$batches;
    }

    /**
     * @param  array{debits: int, credits: int, lines: list<array{chart_code: string, external_account: string, debit_minor: int, credit_minor: int}>}  $batch
     */
    public function export(AccountingLink $link, string $businessDate, array $batch): string
    {
        $ref = "PMS-{$link->branch_id}-{$businessDate}-{$link->provider}";

        self::$batches[$ref] ??= [
            'link_id' => $link->id,
            'business_date' => $businessDate,
            'batch' => $batch,
        ];

        return 'fake-'.$ref;
    }
}
