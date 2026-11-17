<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\VatWorkpaperService;
use App\Support\TenantDatabaseContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tax:vat-workpaper {tenant : Tenant ID} {branch : Branch ID} {month : Reporting month in YYYY-MM format} {--output= : Optional JSON output path}')]
#[Description('Build a property VAT workpaper for return preparation.')]
class ExportVatWorkpaper extends Command
{
    public function handle(TenantDatabaseContext $tenantContext, VatWorkpaperService $workpapers): int
    {
        $tenantId = $this->argument('tenant');
        $branchId = $this->argument('branch');
        $month = $this->argument('month');

        if (! is_numeric($tenantId) || ! is_numeric($branchId)) {
            $this->error('Tenant and branch must be numeric IDs.');

            return self::INVALID;
        }

        $report = $tenantContext->run(
            null,
            (int) $tenantId,
            fn (): array => $workpapers->forMonth(
                Branch::query()->where('tenant_id', (int) $tenantId)->findOrFail((int) $branchId),
                $month,
            ),
            actorType: 'artisan',
        );

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $output = $this->option('output');

        if (is_string($output) && $output !== '') {
            if (file_put_contents($output, $json.PHP_EOL) === false) {
                $this->error('Could not write the VAT workpaper output file.');

                return self::FAILURE;
            }

            $this->info("VAT workpaper written to {$output}");

            return self::SUCCESS;
        }

        $this->line($json);

        return self::SUCCESS;
    }
}
