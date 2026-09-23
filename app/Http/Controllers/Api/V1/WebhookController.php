<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiConsumer;
use App\Models\Branch;
use App\Models\WebhookDelivery;
use App\Services\WebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function replay(Request $request, WebhookDispatcher $dispatcher): JsonResponse
    {
        /** @var Branch $branch */
        $branch = $request->attributes->get('branch');

        $request->validate([
            'delivery_id' => 'required|integer',
        ]);

        $delivery = WebhookDelivery::where('id', $request->integer('delivery_id'))
            ->whereHas('consumer', fn ($q) => $q
                ->where('branch_id', $branch->id)
                ->orWhere(fn ($qq) => $qq->whereNull('branch_id')->whereJsonContains('branch_ids', $branch->id)))
            ->firstOrFail();

        $delivery = $dispatcher->replay($delivery);

        return response()->json([
            'data' => [
                'id' => $delivery->id,
                'event' => $delivery->event,
                'status' => $delivery->status,
                'signature' => $delivery->signature,
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var Branch $branch */
        $branch = $request->attributes->get('branch');

        /** @var ApiConsumer $consumer */
        $consumer = $request->attributes->get('api_consumer');

        $deliveries = WebhookDelivery::where('api_consumer_id', $consumer->id)
            ->latest('id')
            ->limit(50)
            ->get(['id', 'event', 'status', 'attempts', 'created_at'])
            ->map(fn (WebhookDelivery $d) => [
                'id' => $d->id,
                'event' => $d->event,
                'status' => $d->status,
                'attempts' => $d->attempts,
                'created_at' => $d->created_at?->toIso8601String(),
            ])
            ->all();

        return response()->json(['data' => $deliveries]);
    }
}
