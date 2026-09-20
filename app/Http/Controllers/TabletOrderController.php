<?php

namespace App\Http\Controllers;

use App\Models\TabletOrder;
use App\Models\TabletSession;
use App\Services\TabletOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TabletOrderController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'session_id' => 'required|exists:tablet_sessions,id',
            'items' => 'required|array|min:1',
            'items.*.menu_item_id' => 'required|exists:menu_items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'dietary_requests' => 'nullable|array',
        ]);

        $session = TabletSession::findOrFail($request->integer('session_id'));

        if ($session->isStale()) {
            abort(410, 'Session expired. Please re-pair the tablet.');
        }

        $service = new TabletOrderService;

        $allInput = $request->all();
        $rawItems = $allInput['items'] ?? null;
        $items = [];
        if (is_array($rawItems)) {
            foreach ($rawItems as $rawItem) {
                if (is_array($rawItem)
                    && array_key_exists('menu_item_id', $rawItem)
                    && array_key_exists('quantity', $rawItem)
                    && is_int($rawItem['menu_item_id'])
                    && is_int($rawItem['quantity'])
                ) {
                    $items[] = [
                        'menu_item_id' => $rawItem['menu_item_id'],
                        'quantity' => $rawItem['quantity'],
                    ];
                }
            }
        }

        $rawDietary = $allInput['dietary_requests'] ?? null;
        $dietaryRequests = is_array($rawDietary) ? array_filter($rawDietary, fn ($key) => is_string($key), ARRAY_FILTER_USE_KEY) : null;

        $order = $service->createOrder($session, $items, $dietaryRequests);

        $service->initiatePayment($order, $request->string('payment_method', 'room_charge')->value());

        return redirect()->route('tablet.order.track', ['tabletOrder' => $order->id]);
    }

    public function show(TabletOrder $tabletOrder): Response
    {
        return Inertia::render('tablet/TrackOrder', [
            'order' => $tabletOrder,
        ]);
    }

    public function track(TabletOrder $tabletOrder): Response
    {
        return Inertia::render('tablet/TrackOrder', [
            'order' => $tabletOrder,
        ]);
    }

    public function cancel(Request $request, TabletOrder $tabletOrder): RedirectResponse
    {
        (new TabletOrderService)->cancelOrder($tabletOrder);

        return $this->flashSuccess('Order cancelled.');
    }
}
