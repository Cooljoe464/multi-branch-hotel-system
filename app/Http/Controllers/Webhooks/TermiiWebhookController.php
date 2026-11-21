<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TermiiWebhookController extends Controller
{
    /**
     * Handle Termii Delivery Status Report (DSR) webhook callbacks.
     */
    public function handleDsr(Request $request): JsonResponse
    {
        $payload = $request->all();

        $messageId = $payload['message_id'] ?? $payload['id'] ?? null;
        $status = strtolower((string) ($payload['status'] ?? 'unknown'));
        $receiver = $payload['receiver'] ?? $payload['to'] ?? null;

        Log::info('Termii DSR received', [
            'message_id' => $messageId,
            'status' => $status,
            'receiver' => $receiver,
        ]);

        return response()->json([
            'status' => 'acknowledged',
            'message_id' => $messageId,
        ]);
    }
}
