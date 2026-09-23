<?php

namespace App\Jobs;

use App\Models\GuestIdentityDocument;
use App\Models\RetentionPolicy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Nightly retention sweep. folio_lines is a legal hold and is skipped
 * by design; id_scans lose files + payloads past their policy;
 * marketing defers to the consent ledger (4.3). Every action is a
 * delete-if-exists or null-if-set, so re-runs are natural no-ops.
 */
class RetentionRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('reports');
    }

    /**
     * @return array{policies: int, scans_cleared: int}
     */
    public function handle(): array
    {
        $policies = 0;
        $scans = 0;

        foreach (RetentionPolicy::orderBy('id')->get() as $policy) {
            if ($policy->data_class === 'folio_lines') {
                continue;
            }

            if ($policy->data_class === 'id_scans') {
                $scans += $this->clearScans($policy->retain_days);
            }

            $policies++;
        }

        Log::info('Retention sweep completed.', ['policies' => $policies, 'scans_cleared' => $scans]);

        return ['policies' => $policies, 'scans_cleared' => $scans];
    }

    private function clearScans(int $retainDays): int
    {
        $cutoff = Carbon::today()->subDays($retainDays)->toDateString();
        $cleared = 0;

        GuestIdentityDocument::where('created_at', '<', $cutoff)
            ->where(function ($query) {
                $query->whereNotNull('scan_path')->orWhereNotNull('ocr_result');
            })
            ->orderBy('id')
            ->chunkById(200, function ($docs) use (&$cleared) {
                foreach ($docs as $doc) {
                    DB::transaction(function () use ($doc, &$cleared) {
                        $locked = GuestIdentityDocument::where('id', $doc->id)->lockForUpdate()->first();

                        if (! $locked) {
                            return;
                        }

                        if (is_string($locked->scan_path) && $locked->scan_path !== '' && Storage::disk('r2')->exists($locked->scan_path)) {
                            Storage::disk('r2')->delete($locked->scan_path);
                            $cleared++;
                        }

                        $locked->update(['scan_path' => null, 'ocr_result' => null]);
                    });
                }
            });

        return $cleared;
    }
}
