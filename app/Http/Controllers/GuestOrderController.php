<?php

namespace App\Http\Controllers;

use App\Models\KotItem;
use App\Models\MenuItem;
use App\Models\MenuItemStation;
use App\Models\Outlet;
use App\Models\Reservation;
use App\Models\TabletOrder;
use App\Services\FolioService;
use App\Services\KotRoutingService;
use App\Services\PaymentGuardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GuestOrderController extends Controller
{
    public function outlets(string $confirmationNumber): Response
    {
        $reservation = Reservation::where('confirmation_number', $confirmationNumber)
            ->where('status', 'checked_in')
            ->with('branch')
            ->firstOrFail();

        $outlets = Outlet::forBranch($reservation->branch_id)
            ->active()
            ->get()
            ->filter(fn (Outlet $outlet) => $outlet->kitchenStations()->exists())
            ->values();

        return Inertia::render('guest/Order', [
            'reservation' => [
                'id' => $reservation->id,
                'confirmation_number' => $reservation->confirmation_number,
                'guest_name' => $reservation->guest_name,
                'branch_name' => $reservation->branch->name,
            ],
            'outlets' => $outlets->map(fn (Outlet $o) => [
                'id' => $o->id,
                'name' => $o->name,
                'code' => $o->code,
                'type' => $o->type,
            ]),
        ]);
    }

    public function menu(string $confirmationNumber, string $outletCode): Response
    {
        $reservation = Reservation::where('confirmation_number', $confirmationNumber)
            ->where('status', 'checked_in')
            ->with('branch')
            ->firstOrFail();

        $outlet = Outlet::where('code', $outletCode)
            ->where('branch_id', $reservation->branch_id)
            ->active()
            ->firstOrFail();

        $stationIds = $outlet->kitchenStations()->pluck('id');

        $menuItems = MenuItem::where('is_active', true)
            ->where('is_available', true)
            ->whereIn('id', MenuItemStation::whereIn('kitchen_station_id', $stationIds)->pluck('menu_item_id'))
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return Inertia::render('guest/OrderMenu', [
            'reservation' => [
                'id' => $reservation->id,
                'confirmation_number' => $reservation->confirmation_number,
                'guest_name' => $reservation->guest_name,
            ],
            'outlet' => [
                'id' => $outlet->id,
                'name' => $outlet->name,
                'code' => $outlet->code,
                'type' => $outlet->type,
            ],
            'menuItems' => $menuItems->map(fn (MenuItem $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'price' => $m->price,
                'category' => $m->category,
                'description' => $m->description,
            ]),
        ]);
    }

    public function store(Request $request, string $confirmationNumber): RedirectResponse
    {
        $request->validate([
            'outlet_code' => 'required|string',
            'items' => 'required|array|min:1',
            'items.*.menu_item_id' => 'required|integer|exists:menu_items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.notes' => 'nullable|string|max:500',
        ]);

        $reservation = Reservation::where('confirmation_number', $confirmationNumber)
            ->where('status', 'checked_in')
            ->firstOrFail();

        $outlet = Outlet::where('code', $request->string('outlet_code'))
            ->where('branch_id', $reservation->branch_id)
            ->active()
            ->firstOrFail();

        $branch = $reservation->branch;
        /** @var list<array{menu_item_id: int, quantity: int, notes?: string}> $items */
        $items = array_values((array) $request->input('items'));

        $subtotal = 0;
        $processedItems = [];

        foreach ($items as $item) {
            $menuItem = MenuItem::findOrFail($item['menu_item_id']);
            $quantity = $item['quantity'];
            $itemTotal = $menuItem->price * $quantity;
            $subtotal += $itemTotal;

            $processedItems[] = [
                'menu_item_id' => $menuItem->id,
                'name' => $menuItem->name,
                'quantity' => $quantity,
                'unit_price' => $menuItem->price,
                'total' => $itemTotal,
                'notes' => $item['notes'] ?? null,
            ];
        }

        $taxRate = $branch->tax_rate / 100;
        $taxAmount = (int) ($subtotal * $taxRate);
        $total = $subtotal + $taxAmount;

        $tabletOrder = TabletOrder::create([
            'branch_id' => $branch->id,
            'currency_code' => $branch->currency_code,
            'reservation_id' => $reservation->id,
            'items' => $processedItems,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'status' => 'pending',
            'payment_method' => 'room_charge',
            'payment_status' => 'pending',
        ]);

        $folio = $reservation->folios()->where('status', 'open')->first();

        if (! $folio) {
            $folioService = new FolioService;
            $folio = $folioService->createFolio($branch->id, $reservation->id);
        }

        $paymentGuard = new PaymentGuardService;
        $posCharge = $paymentGuard->createCharge(
            $branch->id,
            $reservation->id,
            $folio->id,
            $outlet->code,
            $processedItems,
            $subtotal,
            $taxAmount,
            $total
        );

        $paymentGuard->dispatchOrder($posCharge);

        $routingService = new KotRoutingService;
        $routedItems = $routingService->routeItems($processedItems, $branch->id);

        foreach ($routedItems as $routedItem) {
            KotItem::create([
                'pos_charge_id' => $posCharge->id,
                'branch_id' => $branch->id,
                'outlet' => $routedItem['outlet'],
                'item_name' => $routedItem['name'],
                'quantity' => $routedItem['quantity'],
                'status' => 'pending',
                'priority' => 'normal',
                'notes' => $routedItem['notes'] ?? null,
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Order placed! Your order is being prepared.']);

        return redirect()->route('guest.order.track', [
            'confirmationNumber' => $confirmationNumber,
            'orderId' => $tabletOrder->id,
        ]);
    }

    public function track(string $confirmationNumber, int $orderId): Response
    {
        $reservation = Reservation::where('confirmation_number', $confirmationNumber)
            ->where('status', 'checked_in')
            ->firstOrFail();

        $order = TabletOrder::where('id', $orderId)
            ->where('reservation_id', $reservation->id)
            ->with('reservation.branch')
            ->firstOrFail();

        return Inertia::render('guest/OrderTrack', [
            'reservation' => [
                'confirmation_number' => $reservation->confirmation_number,
                'guest_name' => $reservation->guest_name,
            ],
            'order' => [
                'id' => $order->id,
                'items' => $order->items,
                'subtotal' => $order->subtotal,
                'tax_amount' => $order->tax_amount,
                'total' => $order->total,
                'status' => $order->status,
                'created_at' => $order->created_at?->toIso8601String() ?? now()->toIso8601String(),
            ],
        ]);
    }
}
