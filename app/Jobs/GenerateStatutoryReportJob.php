<?php

namespace App\Jobs;

use App\Models\StatutoryReport;
use App\Services\StatutoryReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateStatutoryReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $reportId,
    ) {
        $this->onQueue('reports');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        $report = StatutoryReport::find($this->reportId);

        if (! $report || $report->status === StatutoryReport::STATUS_READY) {
            return;
        }

        try {
            (new StatutoryReportService)->build($report);
        } catch (\Throwable $e) {
            Log::error('Statutory report build failed.', ['report_id' => $report->id, 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        $report = StatutoryReport::find($this->reportId);

        if ($report && $report->status !== StatutoryReport::STATUS_READY) {
            (new StatutoryReportService)->fail($report, $e->getMessage());
        }
    }
}
