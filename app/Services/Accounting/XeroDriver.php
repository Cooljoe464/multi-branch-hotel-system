<?php

namespace App\Services\Accounting;

use App\Exceptions\AvailabilityException;
use App\Models\AccountingExport;
use App\Models\AccountingLink;
use Illuminate\Support\Facades\Http;

/**
 * Xero manual journals. Amounts post in branch minor units converted
 * to major with two decimals; the PMS reference rides the narration
 * so finance can trace every line back to the export.
 */
class XeroDriver implements AccountingExporter
{
    /**
     * @param  array{debits: int, credits: int, lines: list<array{chart_code: string, external_account: string, debit_minor: int, credit_minor: int}>}  $batch
     */
    public function export(AccountingLink $link, string $businessDate, array $batch): string
    {
        $tokens = $link->readTokens();
        $access = $tokens['access_token'] ?? null;
        $tenant = $tokens['tenant_id'] ?? null;

        if (! is_string($access) || $access === '' || ! is_string($tenant) || $tenant === '') {
            throw new AvailabilityException('ACCOUNTING_AUTH', 'Xero is not connected for this property.');
        }

        $ref = AccountingExport::externalRef($link->branch_id, $businessDate);

        $lines = [];
        foreach ($batch['lines'] as $line) {
            $net = ($line['debit_minor'] - $line['credit_minor']) / 100;
            $lines[] = [
                'AccountID' => $line['external_account'],
                'Description' => "{$ref} / {$line['chart_code']}",
                'LineAmount' => round($net, 2),
            ];
        }

        $response = Http::timeout(30)->withToken($access)->withHeaders([
            'Xero-Tenant-Id' => $tenant,
            'Content-Type' => 'application/json',
        ])->post('https://api.xero.com/api.xro/2.0/ManualJournals', [
            'Narration' => $ref,
            'Date' => $businessDate,
            'JournalLines' => $lines,
        ]);

        if (! $response->successful()) {
            throw new AvailabilityException('ACCOUNTING_SEND', "Xero rejected the batch ({$response->status()}).");
        }

        $id = $response->json('ManualJournals.0.ManualJournalID');

        if (! is_string($id) || $id === '') {
            throw new AvailabilityException('ACCOUNTING_SEND', 'Xero returned no journal id.');
        }

        return $id;
    }
}
