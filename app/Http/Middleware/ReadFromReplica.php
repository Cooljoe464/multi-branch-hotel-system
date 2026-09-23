<?php

namespace App\Http\Middleware;

use App\Events\ReplicaLagHigh;
use App\Services\ReadRouter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route heavy report reads to the replica and report its lag.
 * Safe methods only by construction (applied to GET routes).
 * Lag over 60s fires ReplicaLagHigh and marks the response stale;
 * OLTP never waits for the replica.
 */
class ReadFromReplica
{
    public function handle(Request $request, Closure $next): Response
    {
        ReadRouter::enable();

        $response = $next($request);
        if (! $response instanceof Response) {
            abort(500);
        }

        $lag = ReadRouter::lagSeconds();

        if ($lag === null) {
            $response->headers->set('X-Replica-Lag-Seconds', 'unknown');
        } else {
            $response->headers->set('X-Replica-Lag-Seconds', (string) round($lag, 1));

            if ($lag > ReadRouter::LAG_ALERT_SECONDS) {
                $response->headers->set('X-Replica-Stale', 'true');
                event(new ReplicaLagHigh($lag));
            }
        }

        return $response;
    }
}
