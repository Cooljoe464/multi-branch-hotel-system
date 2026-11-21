<?php

namespace App\Jobs;

use App\Models\CallRecord;
use App\Models\ConciergeMessage;
use App\Models\Consent;
use App\Models\GuestIdentityDocument;
use App\Models\RetentionPolicy;
use App\Models\WifiSession;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

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
     * @return array{policies: int, scans_cleared: int, purged: array<string, int>}
     */
    public function handle(): array
    {
        $policies = 0;
        $scans = 0;
        $purged = [
            'id_scans' => 0,
            'wifi_sessions' => 0,
            'call_records' => 0,
            'concierge_messages' => 0,
            'marketing_consents' => 0,
            'activity_logs' => 0,
        ];

        foreach (RetentionPolicy::orderBy('id')->get() as $policy) {
            if ($policy->data_class === 'folio_lines') {
                continue;
            }

            match ($policy->data_class) {
                'id_scans' => $scans += $this->clearScans($policy->retain_days),
                'wifi_sessions' => $purged['wifi_sessions'] += $this->clearWifiSessions($policy->retain_days, $policy->action),
                'call_records' => $purged['call_records'] += $this->clearCallRecords($policy->retain_days),
                'concierge_messages' => $purged['concierge_messages'] += $this->clearConciergeMessages($policy->retain_days),
                'marketing_consents' => $purged['marketing_consents'] += $this->clearMarketingConsents($policy->retain_days),
                'activity_logs' => $purged['activity_logs'] += $this->clearActivityLogs($policy->retain_days),
                default => null,
            };

            $policies++;
        }

        $purged['id_scans'] = $scans;

        Log::info('Retention sweep completed.', ['policies' => $policies, 'scans_cleared' => $scans, 'purged' => $purged]);

        return [
            'policies' => $policies,
            'scans_cleared' => $scans,
            'purged' => $purged,
        ];
    }

    private function clearWifiSessions(int $retainDays, string $action): int
    {
        $cutoff = Carbon::today()->subDays($retainDays);
        $query = WifiSession::where('created_at', '<', $cutoff);

        if ($action === RetentionPolicy::ACTION_ANONYMIZE) {
            return $query->update([
                'username' => null,
                'password' => null,
                'voucher' => 'ANONYMIZED',
            ]);
        }

        return $query->delete();
    }

    private function clearCallRecords(int $retainDays): int
    {
        $cutoff = Carbon::today()->subDays($retainDays);

        return CallRecord::where('created_at', '<', $cutoff)->delete();
    }

    private function clearConciergeMessages(int $retainDays): int
    {
        $cutoff = Carbon::today()->subDays($retainDays);

        return ConciergeMessage::where('created_at', '<', $cutoff)->delete();
    }

    private function clearMarketingConsents(int $retainDays): int
    {
        $cutoff = Carbon::today()->subDays($retainDays);

        return Consent::where('granted', false)->where('updated_at', '<', $cutoff)->delete();
    }

    private function clearActivityLogs(int $retainDays): int
    {
        if (! class_exists(Activity::class) || ! Schema::hasTable('activity_log')) {
            return 0;
        }

        $cutoff = Carbon::today()->subDays($retainDays);

        return Activity::where('created_at', '<', $cutoff)->delete();
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
