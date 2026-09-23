<?php

namespace App\Services\Accounting;

use App\Exceptions\AvailabilityException;
use App\Models\AccountingExport;
use App\Models\AccountingLink;
use Illuminate\Support\Facades\Http;

/**
 * QuickBooks journal entries. The PMS reference is the DocNumber,
 * so the pre-flight query returns the original entry instead of
 * double-posting when our unique key and their ledger disagree.
 */
class QuickBooksDriver implements AccountingExporter
{
    /**
     * @param  array{debits: int, credits: int, lines: list<array{chart_code: string, external_account: string, debit_minor: int, credit_minor: int}>}  $batch
     */
    public function export(AccountingLink $link, string $businessDate, array $batch): string
    {
        $tokens = $link->readTokens();
        $access = $tokens['access_token'] ?? null;
        $realm = $tokens['realm_id'] ?? null;

        if (! is_string($access) || $access === '' || ! is_string($realm) || $realm === '') {
            throw new AvailabilityException('ACCOUNTING_AUTH', 'QuickBooks is not connected for this property.');
        }

        $base = $link->sandbox
            ? "https://sandbox-quickbooks.api.intuit.com/v3/company/{$realm}"
            : "https://quickbooks.api.intuit.com/v3/company/{$realm}";

        $ref = AccountingExport::externalRef($link->branch_id, $businessDate);

        $existing = Http::timeout(30)->withToken($access)->acceptJson()
            ->get("{$base}/query", ['query' => "select Id from JournalEntry where DocNumber = '{$ref}'"]);

        $found = $existing->json('QueryResponse.JournalEntry.0.Id');

        if (is_string($found) && $found !== '') {
            return $found;
        }

        $lines = [];
        foreach ($batch['lines'] as $line) {
            $lines[] = [
                'DetailType' => 'JournalEntryLineDetail',
                'Amount' => round(($line['debit_minor'] + $line['credit_minor']) / 100, 2),
                'JournalEntryLineDetail' => [
                    'PostingType' => $line['debit_minor'] >= $line['credit_minor'] ? 'Debit' : 'Credit',
                    'AccountRef' => ['value' => $line['external_account']],
                ],
            ];
        }

        $response = Http::timeout(30)->withToken($access)->acceptJson()->post("{$base}/journalentry", [
            'DocNumber' => $ref,
            'TxnDate' => $businessDate,
            'PrivateNote' => $ref,
            'Line' => $lines,
        ]);

        if (! $response->successful()) {
            throw new AvailabilityException('ACCOUNTING_SEND', "QuickBooks rejected the batch ({$response->status()}).");
        }

        $id = $response->json('JournalEntry.Id');

        if (! is_string($id) || $id === '') {
            throw new AvailabilityException('ACCOUNTING_SEND', 'QuickBooks returned no entry id.');
        }

        return $id;
    }
}
