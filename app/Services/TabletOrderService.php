<?php

namespace App\Services;

use App\Models\KotItem;
use App\Models\MenuItem;
use App\Models\TabletOrder;
use App\Models\TabletSession;
use Illuminate\Support\Facades\DB;

class TabletOrderService
{
    /**
     * @param  list<array{menu_item_id: int, quantity?: int, notes?: string}>  $items
     * @param  array<string, mixed>|null  $dietaryRequests
     */
    public function createOrder(TabletSession $session, array $items, ?array $dietaryRequests = null): TabletOrder
    {
        $reservation = $session->reservation;
        $branch = $session->branch;

        $subtotal = 0;
        $processedItems = [];

        foreach ($items as $item) {
            $menuItem = MenuItem::findOrFail($item['menu_item_id']);
            $quantity = $item['quantity'] ?? 1;
            $total = $menuItem->price * $quantity;
            $subtotal += $total;

            $processedItems[] = [
                'menu_item_id' => $menuItem->id,
                'name' => $menuItem->name,
                'quantity' => $quantity,
                'unit_price' => $menuItem->price,
                'total' => $total,
                'notes' => $item['notes'] ?? null,
            ];
        }

        $taxRate = $branch->tax_rate / 100;
        $taxAmount = (int) ($subtotal * $taxRate);

        return TabletOrder::create([
            'branch_id' => $branch->id,
            'currency_code' => $branch->currency_code,
            'tablet_session_id' => $session->id,
            'reservation_id' => $reservation->id,
            'items' => $processedItems,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $subtotal + $taxAmount,
            'status' => 'pending',
            'payment_method' => 'room_charge',
            'payment_status' => 'pending',
            'dietary_requests' => $dietaryRequests,
        ]);
    }

    public function initiatePayment(TabletOrder $order, string $method): void
    {
        $order->update(['payment_method' => $method]);
    }

    public function handlePaymentSuccess(TabletOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $order->update([
                'payment_status' => 'paid',
                'status' => 'preparing',
            ]);

            $reservation = $order->reservation;
            $branch = $order->branch;

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
                'tablet',
                array_values($order->items),
                $order->subtotal,
                $order->tax_amount,
                $order->total
            );

            $paymentGuard->dispatchOrder($posCharge);

            $routingService = new KotRoutingService;
            $routedItems = $routingService->routeItems(array_values($order->items), $branch->id);

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

            $inventoryService = new InventoryService;
            foreach ($order->items as $item) {
                $menuItem = MenuItem::find($item['menu_item_id']);
                if ($menuItem) {
                    $inventoryService->deductIngredients($menuItem, $item['quantity'] ?? 1);
                }
            }
        });
    }

    public function cancelOrder(TabletOrder $order): void
    {
        $order->update(['status' => 'cancelled']);
    }
}
