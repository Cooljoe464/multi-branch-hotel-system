<?php

namespace App\Jobs;

use App\Models\ChannelProviderModel;
use App\Services\ChannelService;
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
        $from = Carbon::tomorrow()->toDateString();
        $to = Carbon::tomorrow()->addDays(14)->toDateString();

        $providers = 0;
        $drifted = 0;

        ChannelProviderModel::where('is_active', true)->orderBy('id')->chunkById(50, function ($chunk) use ($from, $to, &$providers, &$drifted) {
            foreach ($chunk as $provider) {
                $result = (new ChannelService)->reconcile($provider, $from, $to);
                $providers++;
                $drifted += $result['drifted'];
            }
        });

        Log::info('Nightly channel reconciliation completed.', ['providers' => $providers, 'drifted' => $drifted]);

        return ['providers' => $providers, 'drifted' => $drifted];
    }
}
