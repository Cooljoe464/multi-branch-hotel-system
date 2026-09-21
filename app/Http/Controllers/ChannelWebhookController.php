<?php

namespace App\Http\Controllers;

use App\Exceptions\AvailabilityException;
use App\Models\ChannelProviderModel;
use App\Services\ChannelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChannelWebhookController extends Controller
{
    /**
     * Inbound OTA booking webhook. Authenticated by HMAC
     * (X-Channel-Signature over the raw body with the provider secret),
     * idempotent on the channel booking id.
     */
    public function store(Request $request, ChannelProviderModel $channelProvider): JsonResponse
    {
        $service = new ChannelService;

        $signature = $request->header('X-Channel-Signature');
        $signature = is_string($signature) ? $signature : null;

        if (! $service->verifySignature($channelProvider, $request->getContent(), $signature)) {
            return response()->json(['message' => 'Invalid signature.', 'code' => 'CHANNEL_SIGNATURE_INVALID'], 401);
        }

        $decoded = json_decode($request->getContent(), true);

        if (! is_array($decoded)) {
            return response()->json(['message' => 'Invalid payload.', 'code' => 'CHANNEL_BOOKING_INVALID'], 422);
        }

        // JSON objects decode with string keys; normalize so downstream
        // code works on array<string, mixed>.
        $payload = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $payload[$key] = $value;
            }
        }

        try {
            $reservation = $service->handleInbound($channelProvider, $payload);
        } catch (AvailabilityException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->availabilityCode,
                'unavailable_dates' => $e->unavailableDates,
            ], $e->availabilityCode === 'OVERBOOK_FORBIDDEN' ? 403 : 422);
        }

        return response()->json([
            'reservation_id' => $reservation->id,
            'confirmation_number' => $reservation->confirmation_number,
        ], 201);
    }
}
