<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EdgeAgent;
use App\Services\EdgeAgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EdgeAgentController extends Controller
{
    public function heartbeat(Request $request, EdgeAgentService $agents): JsonResponse
    {
        /** @var EdgeAgent $agent */
        $agent = $request->attributes->get('edge_agent');

        return response()->json(['data' => $agents->heartbeat($agent, $request->header('X-Edge-Protocol') ?? '')]);
    }

    public function policy(EdgeAgentService $agents): JsonResponse
    {
        return response()->json(['data' => [
            'current' => config()->string('edge.current_version'),
            'supported' => $agents->compatibility(config()->string('edge.current_version'))['supported'],
        ]]);
    }
}
