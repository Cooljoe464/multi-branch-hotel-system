<?php

namespace App\Services\Accounting;

use App\Exceptions\AvailabilityException;
use App\Models\AccountingExport;
use App\Models\AccountingLink;
use Illuminate\Support\Facades\Http;

/**
 * Sage journals. The PMS reference posts as the journal reference;
 * re-exports query it first so a retried date never duplicates.
 */
class SageDriver implements AccountingExporter
{
    /**
     * @param  array{debits: int, credits: int, lines: list<array{chart_code: string, external_account: string, debit_minor: int, credit_minor: int}>}  $batch
     */
    public function export(AccountingLink $link, string $businessDate, array $batch): string
    {
        $tokens = $link->readTokens();
        $access = $tokens['access_token'] ?? null;

        if (! is_string($access) || $access === '') {
            throw new AvailabilityException('ACCOUNTING_AUTH', 'Sage is not connected for this property.');
        }

        $base = $link->sandbox ? 'https://api.sage.com/sandbox/v1' : 'https://api.sage.com/v1';
        $ref = AccountingExport::externalRef($link->branch_id, $businessDate);

        $existing = Http::timeout(30)->withToken($access)->acceptJson()
            ->get("{$base}/journals", ['reference' => $ref]);

        $found = $existing->json('data.0.id');

        if (is_string($found) && $found !== '') {
            return $found;
        }

        $lines = [];
        foreach ($batch['lines'] as $line) {
            $lines[] = [
                'ledger_account_id' => $line['external_account'],
                'debit' => round($line['debit_minor'] / 100, 2),
                'credit' => round($line['credit_minor'] / 100, 2),
                'description' => "{$ref} / {$line['chart_code']}",
            ];
        }

        $response = Http::timeout(30)->withToken($access)->acceptJson()->post("{$base}/journals", [
            'reference' => $ref,
            'date' => $businessDate,
            'lines' => $lines,
        ]);

        if (! $response->successful()) {
            throw new AvailabilityException('ACCOUNTING_SEND', "Sage rejected the batch ({$response->status()}).");
        }

        $id = $response->json('data.id');

        if (! is_string($id) || $id === '') {
            throw new AvailabilityException('ACCOUNTING_SEND', 'Sage returned no journal id.');
        }

        return $id;
    }
}
