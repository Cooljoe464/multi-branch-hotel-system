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
        $compatibility = $this->agents->compatibility(is_string($version) ? $version : '');

        if (! $compatibility['accepted']) {
            return response()->json([
                'message' => 'Unsupported edge-agent protocol version.',
                'current_version' => config()->string('edge.current_version'),
                'supported_versions' => $compatibility['supported'],
            ], 426);
        }

        $request->attributes->set('edge_agent', $agent);

        return $next($request);
    }
}
