<?php

namespace App\Jobs;

use App\Events\AccountingExportCompleted;
use App\Events\AccountingExportFailed;
use App\Exceptions\AvailabilityException;
use App\Models\AccountingExport;
use App\Models\AccountingLink;
use App\Models\Branch;
use App\Services\Accounting\AccountingDriverManager;
use App\Services\TrialBalanceService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Export one closed day to the external ledger. Totals are asserted
 * equal to the trial balance before anything leaves the building;
 * unbalanced days refuse with no send. The unique key plus
 * per-export uniqueness make concurrent runs collapse to one.
 */
class ExportDailyJournalJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(private int $branchId, private string $businessDate, private string $provider)
    {
        $this->onQueue('accounting');
    }

    public function uniqueId(): string
    {
        return "accounting:{$this->branchId}:{$this->businessDate}:{$this->provider}";
    }

    /**
     * @return array{export: AccountingExport, duplicate: bool}
     */
    public function export(): array
    {
        $branch = Branch::findOrFail($this->branchId);

        $link = AccountingLink::where('branch_id', $branch->id)
            ->where('provider', $this->provider)
            ->where('is_active', true)
            ->first();

        if (! $link) {
            throw new AvailabilityException('ACCOUNTING_UNLINKED', 'No active link for this provider.');
        }

        $totals = (new TrialBalanceService)->totals($branch, $this->businessDate);

        if (! $totals['balanced'] || $totals['debits'] <= 0) {
            $export = $this->record($branch->id, [
                'status' => AccountingExport::STATUS_FAILED,
                'totals' => $totals,
                'last_error' => 'Day is unbalanced or empty; refused without sending.',
            ]);

            event(new AccountingExportFailed($export));

            return ['export' => $export, 'duplicate' => false];
        }

        $lines = [];
        foreach ($totals['per_account'] as $code => $sides) {
            $external = $link->externalAccountFor($code);

            if ($external === null) {
                throw new AvailabilityException('ACCOUNT_MAP_MISSING', "No external account mapped for {$code}.");
            }

            $lines[] = [
                'chart_code' => $code,
                'external_account' => $external,
                'debit_minor' => $sides['debit'],
                'credit_minor' => $sides['credit'],
            ];
        }

        $existing = AccountingExport::where('branch_id', $branch->id)
            ->where('business_date', $this->businessDate)
            ->where('provider', $this->provider)
            ->where('status', AccountingExport::STATUS_DELIVERED)
            ->first();

        if ($existing) {
            return ['export' => $existing, 'duplicate' => true];
        }

        $externalId = (new AccountingDriverManager)->driver($this->provider)->export($link, $this->businessDate, [
            'debits' => $totals['debits'],
            'credits' => $totals['credits'],
            'lines' => $lines,
        ]);

        $export = $this->record($branch->id, [
            'status' => AccountingExport::STATUS_DELIVERED,
            'external_id' => $externalId,
            'totals' => ['debits' => $totals['debits'], 'credits' => $totals['credits']],
            'last_error' => null,
        ]);

        event(new AccountingExportCompleted($export));

        return ['export' => $export, 'duplicate' => false];
    }

    public function handle(): void
    {
        try {
            $this->export();
        } catch (AvailabilityException $e) {
            $this->record($this->branchId, [
                'status' => AccountingExport::STATUS_FAILED,
                'last_error' => $e->getMessage(),
            ]);
            Log::warning('Accounting export refused.', [
                'branch_id' => $this->branchId,
                'date' => $this->businessDate,
                'code' => $e->availabilityCode,
            ]);

            if ($e->availabilityCode === 'ACCOUNT_MAP_MISSING') {
                throw $e;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function record(int $branchId, array $attributes): AccountingExport
    {
        try {
            return DB::transaction(fn () => AccountingExport::updateOrCreate(
                ['branch_id' => $branchId, 'business_date' => $this->businessDate, 'provider' => $this->provider],
                $attributes,
            ));
        } catch (QueryException) {
            return AccountingExport::where('branch_id', $branchId)
                ->where('business_date', $this->businessDate)
                ->where('provider', $this->provider)
                ->firstOrFail();
        }
    }
}
