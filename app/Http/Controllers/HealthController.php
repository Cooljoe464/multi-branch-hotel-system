<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

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
        ];

        $healthy = collect($checks)->every(fn ($check) => $check['ok']);

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

        return ['ok' => $failed < 100, 'failed_jobs' => $failed];
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
