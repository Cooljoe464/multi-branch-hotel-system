<?php

namespace App\Services\Accounting;

use App\Models\AccountingLink;

interface AccountingExporter
{
    /**
     * Post one balanced batch. Must be provider-idempotent on the
     * external reference: re-posting the same lines returns the
     * original external id instead of duplicating.
     *
     * @param  array{debits: int, credits: int, lines: list<array{chart_code: string, external_account: string, debit_minor: int, credit_minor: int}>}  $batch
     */
    public function export(AccountingLink $link, string $businessDate, array $batch): string;
}
