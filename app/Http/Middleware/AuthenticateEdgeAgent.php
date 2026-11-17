<?php

namespace App\Http\Middleware;

use App\Models\EdgeAgent;
use App\Services\EdgeAgentService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateEdgeAgent
{
    public function __construct(private EdgeAgentService $agents) {}

    /** @param Closure(Request): (Response) $next */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $version = $request->header('X-Edge-Protocol');
        $agent = is_string($token) ? $this->agents->resolve($token) : null;

        abort_unless($agent instanceof EdgeAgent, 401, 'Invalid edge-agent token.');
        abort_unless(is_string($version) && $this->agents->compatibility($version)['accepted'], 426, 'Unsupported edge-agent protocol version.');

        $request->attributes->set('edge_agent', $agent);

        return $next($request);
    }
}
