<?php

namespace App\Jobs;

use App\Models\ChannelProviderModel;
use App\Services\ChannelService;
use App\Support\BranchTime;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class NightlyReconciliationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('channel');
    }

    /**
     * @return array{providers: int, drifted: int}
     */
    public function handle(): array
    {
        $providers = 0;
        $drifted = 0;

        ChannelProviderModel::where('is_active', true)->orderBy('id')->chunkById(50, function ($chunk) use (&$providers, &$drifted) {
            foreach ($chunk as $provider) {
                $tomorrow = BranchTime::now($provider->branch)->addDay()->toDateString();
                $result = (new ChannelService)->reconcile(
                    $provider,
                    $tomorrow,
                    Carbon::parse($tomorrow)->addDays(14)->toDateString()
                );
                $providers++;
                $drifted += $result['drifted'];
            }
        });

        Log::info('Nightly channel reconciliation completed.', ['providers' => $providers, 'drifted' => $drifted]);

        return ['providers' => $providers, 'drifted' => $drifted];
    }
}
