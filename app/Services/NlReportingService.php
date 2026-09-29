<?php

namespace App\Services;

use App\Events\NlQueryCompleted;
use App\Events\NlQueryFailed;
use App\Exceptions\AvailabilityException;
use App\Jobs\NlQueryJob;
use App\Models\Branch;
use App\Models\NlQueryLog;
use App\Models\User;
use App\Services\Reporting\HeuristicDriver;
use App\Services\Reporting\NlDriver;
use App\Services\Reporting\SqlGuard;
use App\Support\BranchTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Governed natural-language reporting. Questions compile to
 * allow-listed SQL (branch injected, replica only, 5k row cap,
 * 15s statement timeout); PII columns mask without guests.view_pii;
 * every ask is hash-logged and idempotently replayable.
 */
class NlReportingService
{
    public const CACHE_TTL = 900;

    public const STATEMENT_TIMEOUT = '15s';

    /**
     * @var list<string>
     */
    public const PII_COLUMNS = ['guest_name', 'guest_email', 'guest_phone'];

    /**
     * @return array{key: string, status: string, columns: list<string>, rows: list<list<mixed>>, total: int, sql: string, title: string, ms: int}
     */
    public function ask(Branch $branch, ?User $user, string $question, ?NlDriver $driver = null, bool $async = false): array
    {
        $driver ??= new HeuristicDriver;
        [$from, $to] = $this->window($branch, $question);

        $parsed = $driver->parse($question, $from, $to);

        if ($parsed === null) {
            throw new AvailabilityException('NL_QUERY_UNKNOWN', 'I cannot answer that yet. Try revenue, occupancy, ADR, arrivals, departures, balances, voids, or refunds with a date range.');
        }

        $bindings = $parsed['bindings'];
        $bindings[0] = $branch->id;
        $parsed['bindings'] = $bindings;

        SqlGuard::validate($parsed['sql']);

        $key = $this->queryKey($branch->id, $user?->id, $question, $from, $to);

        $log = NlQueryLog::firstOrCreate(
            ['query_key' => $key],
            [
                'branch_id' => $branch->id,
                'user_id' => $user?->id,
                'prompt_hash' => hash('sha256', mb_strtolower(trim($question))),
                'sql_hash' => hash('sha256', $parsed['sql']),
                'status' => NlQueryLog::STATUS_PENDING,
            ],
        );

        $cached = $this->cachedResult(Cache::get($this->cacheKey($key, $user?->id)));

        if ($cached !== null) {
            return array_merge($cached, ['key' => $key, 'status' => 'cached']);
        }

        if ($async) {
            NlQueryJob::dispatch($branch->id, $user?->id, $question, $from, $to);

            return [
                'key' => $key,
                'status' => NlQueryLog::STATUS_PENDING,
                'columns' => $parsed['columns'],
                'rows' => [],
                'total' => 0,
                'sql' => $parsed['sql'],
                'title' => $parsed['title'],
                'ms' => 0,
            ];
        }

        return $this->run($branch, $user, $log, $parsed, $key);
    }

    /**
     * @param  array{sql: string, bindings: list<mixed>, columns: list<string>, kind: string, title: string}  $parsed
     * @return array{key: string, status: string, columns: list<string>, rows: list<list<mixed>>, total: int, sql: string, title: string, ms: int}
     */
    public function run(Branch $branch, ?User $user, NlQueryLog $log, array $parsed, string $key): array
    {
        $started = microtime(true);

        try {
            $rows = DB::connection(ReadRouter::connection())->transaction(function () use ($parsed) {
                DB::connection(ReadRouter::connection())->statement("SET LOCAL statement_timeout = '".self::STATEMENT_TIMEOUT."'");

                return DB::connection(ReadRouter::connection())->select($parsed['sql'], $parsed['bindings']);
            });
        } catch (\Throwable $e) {
            $log->update(['status' => NlQueryLog::STATUS_FAILED, 'last_error' => substr($e->getMessage(), 0, 500)]);
            event(new NlQueryFailed($log, substr($e->getMessage(), 0, 200)));

            throw new AvailabilityException('NL_QUERY_FAILED', 'The query failed to run.');
        }

        $mask = ! ($user && ($user->can('guests.view_pii') || (bool) ($user->is_global_admin ?? false)));

        $out = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($parsed['columns'] as $column) {
                $value = is_object($row) ? ($row->{$column} ?? null) : null;
                $cells[] = $mask && in_array($column, self::PII_COLUMNS, true) && is_string($value) && $value !== ''
                    ? '•••'.substr(hash('sha256', $value), 0, 6)
                    : $value;
            }
            $out[] = $cells;
        }

        $ms = (int) round((microtime(true) - $started) * 1000);

        $log->update(['status' => NlQueryLog::STATUS_COMPLETED, 'rows' => count($out), 'duration_ms' => $ms, 'last_error' => null]);

        $result = [
            'key' => $key,
            'status' => NlQueryLog::STATUS_COMPLETED,
            'columns' => $parsed['columns'],
            'rows' => $out,
            'total' => count($out),
            'sql' => $parsed['sql'],
            'title' => $parsed['title'],
            'ms' => $ms,
        ];

        Cache::put($this->cacheKey($key, $user?->id), $result, self::CACHE_TTL);
        event(new NlQueryCompleted($log));

        return $result;
    }

    /**
     * @return array{key: string, status: string, columns: list<string>, rows: list<list<mixed>>, total: int, sql: string, title: string, ms: int}|null
     */
    public function poll(string $key, ?User $user): ?array
    {
        $cached = $this->cachedResult(Cache::get($this->cacheKey($key, $user?->id)));

        if ($cached === null) {
            return null;
        }

        $log = NlQueryLog::where('query_key', $key)->first();

        return array_merge($cached, ['key' => $key, 'status' => $log ? $log->status : NlQueryLog::STATUS_PENDING]);
    }

    public function queryKey(int $branchId, ?int $userId, string $question, string $from, string $to): string
    {
        return hash('sha256', implode('|', [$branchId, $userId ?? 0, mb_strtolower(trim($question)), $from, $to]));
    }

    /**
     * Cache entries are untrusted bytes: validate the shape before
     * trusting it, so a poisoned or version-skewed entry degrades
     * to a re-run instead of a crash.
     *
     * @return array{key: string, status: string, columns: list<string>, rows: list<list<mixed>>, total: int, sql: string, title: string, ms: int}|null
     */
    private function cachedResult(mixed $cached): ?array
    {
        if (! is_array($cached)) {
            return null;
        }

        $columns = $cached['columns'] ?? null;
        $rows = $cached['rows'] ?? null;

        if (! is_array($columns) || ! is_array($rows)) {
            return null;
        }

        foreach ($columns as $column) {
            if (! is_string($column)) {
                return null;
            }
        }

        foreach ($rows as $row) {
            if (! is_array($row) || ! array_is_list($row)) {
                return null;
            }
        }

        $sql = $cached['sql'] ?? null;
        $title = $cached['title'] ?? null;
        $ms = $cached['ms'] ?? null;

        if (! is_string($sql) || ! is_string($title) || ! is_int($ms)) {
            return null;
        }

        return [
            'key' => '',
            'status' => is_string($cached['status'] ?? null) ? $cached['status'] : '',
            'columns' => array_values($columns),
            'rows' => array_values($rows),
            'total' => is_int($cached['total'] ?? null) ? $cached['total'] : count($rows),
            'sql' => $sql,
            'title' => $title,
            'ms' => $ms,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function window(Branch $branch, string $question): array
    {
        $today = BranchTime::today($branch);
        $q = mb_strtolower($question);

        if (preg_match('/\b(20\d{2}-\d{2}-\d{2})\b(?:\s*(?:to|–|-)\s*\b(20\d{2}-\d{2}-\d{2})\b)?/', $q, $m)) {
            $from = $m[1];
            $to = $m[2] ?? $m[1];

            return [$from, $to];
        }

        if (str_contains($q, 'yesterday')) {
            $day = Carbon::parse($today)->subDay()->toDateString();

            return [$day, $day];
        }

        if (preg_match('/\btoday\b/', $q)) {
            return [$today, $today];
        }

        if (preg_match('/last\s+(\d+)\s+days?/', $q, $m)) {
            $n = min(90, max(1, (int) $m[1]));

            return [Carbon::parse($today)->subDays($n)->toDateString(), $today];
        }

        if (str_contains($q, 'this month')) {
            return [Carbon::parse($today)->startOfMonth()->toDateString(), $today];
        }

        if (str_contains($q, 'tomorrow')) {
            $day = Carbon::parse($today)->addDay()->toDateString();

            return [$day, $day];
        }

        return [Carbon::parse($today)->subDays(30)->toDateString(), $today];
    }

    private function cacheKey(string $key, ?int $userId): string
    {
        return "nlq:{$key}:".($userId ?? 0);
    }
}
