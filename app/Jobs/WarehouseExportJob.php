<?php

namespace App\Jobs;

use App\Events\WarehouseExportFailed;
use App\Events\WarehouseExportReady;
use App\Models\WarehouseManifest;
use App\Services\WarehouseExporter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Nightly warehouse snapshot. One date runs once at a time; the
 * exporter itself is byte-idempotent, so a retried night rewrites
 * the same keys with the same checksums.
 */
class WarehouseExportJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(private string $businessDate)
    {
        $this->onQueue('reports');
    }

    public function uniqueId(): string
    {
        return "warehouse:{$this->businessDate}";
    }

    public function handle(): void
    {
        try {
            $result = (new WarehouseExporter)->export($this->businessDate);

            event(new WarehouseExportReady($result['manifest']));
        } catch (\Throwable $e) {
            $manifest = WarehouseManifest::where('business_date', $this->businessDate)->first();

            event(new WarehouseExportFailed($manifest, $this->businessDate, substr($e->getMessage(), 0, 500)));

            throw $e;
        }
    }
}
