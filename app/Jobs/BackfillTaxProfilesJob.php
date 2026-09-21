<?php

namespace App\Jobs;

use App\Models\Branch;
use App\Models\TaxComponent;
use App\Models\TaxProfile;
use App\Models\Transaction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One-shot tax foundation: a default profile per branch derived from the
 * legacy branch.tax_rate (retired after this), plus legacy snapshots on
 * existing transactions so old lines stay explainable.
 */
class BackfillTaxProfilesJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    /**
     * @return array{profiles: int, snapshots: int}
     */
    public function handle(): array
    {
        $stats = ['profiles' => 0, 'snapshots' => 0];

        Branch::query()->chunkById(100, function ($branches) use (&$stats) {
            foreach ($branches as $branch) {
                $profile = TaxProfile::firstOrCreate(
                    ['branch_id' => $branch->id, 'jurisdiction' => 'NG-DEFAULT'],
                    ['name' => 'Default Nigerian taxes', 'active' => true]
                );

                if ($profile->wasRecentlyCreated) {
                    $stats['profiles']++;
                }

                $rateBps = (int) round((float) $branch->tax_rate * 100);

                if ($rateBps > 0) {
                    TaxComponent::firstOrCreate(
                        ['tax_profile_id' => $profile->id, 'code' => 'VAT', 'applies_to' => 'all'],
                        ['mode' => TaxComponent::MODE_EXCLUSIVE, 'rate_bps' => $rateBps, 'sequence' => 0]
                    );
                }

                $stats['snapshots'] += Transaction::query()
                    ->whereNull('tax_snapshot')
                    ->whereHas('folio', fn ($q) => $q->where('branch_id', $branch->id))
                    ->update([
                        'tax_snapshot' => json_encode([
                            'version' => 'legacy',
                            'profile_id' => $profile->id,
                            'rate_bps' => $rateBps,
                        ]),
                    ]);
            }
        });

        return $stats;
    }
}
