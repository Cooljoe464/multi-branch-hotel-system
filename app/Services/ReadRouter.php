<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Read-replica routing for heavy report/analytics reads. Writes
 * always use the default connection; readers opt in explicitly so
 * a replica outage degrades to the primary instead of failing.
 */
class ReadRouter
{
    public const LAG_ALERT_SECONDS = 60;

    public static function enabled(): bool
    {
        return (bool) app()->bound('db.read_replica') && (bool) app('db.read_replica');
    }

    public static function enable(): void
    {
        app()->instance('db.read_replica', true);
    }

    public static function connection(): string
    {
        if (! self::enabled()) {
            $default = config('database.default');

            return is_string($default) && $default !== '' ? $default : 'pgsql';
        }

        return 'replica';
    }

    /**
     * Replay lag in seconds, or null when unknown (primary node,
     * unreachable replica). Never throws: monitoring must not
     * break the request it observes.
     */
    public static function lagSeconds(): ?float
    {
        try {
            $row = DB::connection('replica')->selectOne(
                'select extract(epoch from (now() - pg_last_xlog_replay_timestamp())) as lag'
            );

            $lag = is_object($row) ? ($row->lag ?? null) : null;

            return is_numeric($lag) ? (float) $lag : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
