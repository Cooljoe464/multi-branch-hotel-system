<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'queue' => $this->checkQueue(),
            'replica' => $this->checkReplica(),
            // Informational only: a fresh environment has no backups
            // yet and must still pass the deploy gate.
            'backups' => $this->checkBackups(),
        ];

        $healthy = collect($checks)->except('backups')->every(fn ($check) => $check['ok']);

        return response()->json(['status' => $healthy ? 'ready' : 'degraded', 'checks' => $checks], $healthy ? 200 : 503);
    }

    public function queues(): JsonResponse
    {
        return response()->json([
            'failed_jobs' => $this->failedJobCount(),
            'failed_growth_note' => 'Alert when this grows between scrapes.',
        ]);
    }

    /**
     * @return array{ok: bool, latency_ms: int|null}
     */
    private function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            DB::select('select 1');
            $latency = (int) ((microtime(true) - $start) * 1000);

            return ['ok' => true, 'latency_ms' => $latency];
        } catch (\Throwable) {
            return ['ok' => false, 'latency_ms' => null];
        }
    }

    /**
     * @return array{ok: bool, latency_ms: int|null}
     */
    private function checkRedis(): array
    {
        try {
            $start = microtime(true);
            Redis::ping();
            $latency = (int) ((microtime(true) - $start) * 1000);

            return ['ok' => true, 'latency_ms' => $latency];
        } catch (\Throwable) {
            return ['ok' => false, 'latency_ms' => null];
        }
    }

    /**
     * @return array{ok: bool, failed_jobs: int}
     */
    private function checkQueue(): array
    {
        $failed = $this->failedJobCount();
        $configured = config('health.failed_jobs_threshold');
        $threshold = is_int($configured) ? $configured : 100;

        return ['ok' => $failed < $threshold, 'failed_jobs' => $failed];
    }

    /**
     * Replica replay lag. A replica mirroring the primary connection
     * means single-node: nothing to lag behind. A separately
     * configured but unreachable replica degrades readiness.
     *
     * @return array{ok: bool, lag_seconds: float|null}
     */
    private function checkReplica(): array
    {
        $default = config('database.default');
        $connection = is_string($default) && $default !== '' ? $default : 'pgsql';
        $primary = config("database.connections.{$connection}");
        $replica = config('database.connections.replica');

        if (is_array($primary) && is_array($replica)
            && ($replica['host'] ?? null) === ($primary['host'] ?? null)
            && ($replica['port'] ?? null) === ($primary['port'] ?? null)
            && ($replica['database'] ?? null) === ($primary['database'] ?? null)
        ) {
            return ['ok' => true, 'lag_seconds' => null];
        }

        try {
            $row = DB::connection('replica')->selectOne(
                'select extract(epoch from (now() - pg_last_xlog_replay_timestamp())) as lag'
            );

            $lag = is_object($row) ? ($row->lag ?? null) : null;

            if (! is_numeric($lag)) {
                return ['ok' => true, 'lag_seconds' => null];
            }

            return ['ok' => (float) $lag <= 60, 'lag_seconds' => round((float) $lag, 1)];
        } catch (\Throwable) {
            return ['ok' => false, 'lag_seconds' => null];
        }
    }

    /**
     * A backup younger than 26h must exist on R2, otherwise the next
     * nightly job is already broken.
     *
     * @return array{ok: bool, newest_backup: string|null}
     */
    private function checkBackups(): array
    {
        try {
            $files = [];

            foreach (Storage::disk('r2')->allFiles('/') as $file) {
                if (str_ends_with($file, '.zip')) {
                    $files[] = $file;
                }
            }

            if ($files === []) {
                return ['ok' => false, 'newest_backup' => null];
            }

            sort($files);
            $newest = end($files);

            $age = time() - Storage::disk('r2')->lastModified($newest);

            return ['ok' => $age < 26 * 3600, 'newest_backup' => $newest];
        } catch (\Throwable) {
            return ['ok' => false, 'newest_backup' => null];
        }
    }

    private function failedJobCount(): int
    {
        try {
            return (int) DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
