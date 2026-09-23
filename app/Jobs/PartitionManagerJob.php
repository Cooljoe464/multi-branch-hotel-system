<?php

namespace App\Jobs;

use App\Services\PartitionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PartitionManagerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('default');
    }

    /**
     * @return list<string>
     */
    public function handle(): array
    {
        $created = (new PartitionManager)->ensureFuturePartitions();

        Log::info('Partitions provisioned.', ['created' => $created]);

        return $created;
    }
}
