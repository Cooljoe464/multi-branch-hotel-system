<?php

namespace App\Http\Controllers;

use App\Models\KotItem;
use App\Models\MenuItem;
use App\Models\MenuItemStation;
use App\Models\Outlet;
use App\Models\Reservation;
use App\Services\FolioService;
use App\Services\InventoryService;
use App\Services\KotRoutingService;
use App\Services\PaymentGuardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PosTerminalController extends Controller
{
    use Concerns\EnsuresBranchAccess;

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

        $query = Outlet::forBranch($branchId)->active();

        // If user is a cashier (not admin), only show assigned outlets
        if (! $user->is_global_admin && $user->hasRole('Cashier')) {
            $query->whereHas('users', fn ($q) => $q->where('users.id', $user->id));
        }

        $outlets = $query->get();

        return Inertia::render('pos/Index', [
            'outlets' => $outlets,
        ]);
    }

    public function terminal(Outlet $outlet): Response
    {
        $user = request()->user();
        abort_unless($user !== null, 401);

        $this->ensureBranchAccess($outlet->branch);

        // If user is a cashier (not admin), verify outlet access
        if (! $user->is_global_admin && $user->hasRole('Cashier')) {
            abort_unless($user->hasPosAccessToOutlet($outlet), 403);
        }

        $branchId = $outlet->branch_id;

        $reservations = Reservation::forBranch($branchId)
            ->checkedIn()
            ->with(['guest', 'room'])
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'guest_name' => $r->guest_name,
                'room_number' => $r->room->number ?? 'N/A',
                'confirmation_number' => $r->confirmation_number,
            ]);

        $stationIds = $outlet->kitchenStations()->pluck('id');

        $menuItems = MenuItem::where('is_active', true)
            ->where('is_available', true)
            ->whereIn('id', MenuItemStation::whereIn('kitchen_station_id', $stationIds)->pluck('menu_item_id'))
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'price' => $m->price,
                'category' => $m->category,
            ]);

        return Inertia::render('pos/Terminal', [
            'outlet' => $outlet,
            'reservations' => $reservations,
            'menuItems' => $menuItems,
        ]);
    }

    public function charge(Request $request, Outlet $outlet): RedirectResponse
    {
        $this->ensureBranchAccess($outlet->branch);

        $mode = $request->input('mode', 'reservation');

        if ($mode === 'walk_in') {
            $request->validate([
                'guest_title' => 'required|string|max:10',
                'guest_name' => 'required|string|max:255',
                'items' => 'required|array|min:1',
                'items.*.menu_item_id' => 'nullable|integer|exists:menu_items,id',
                'items.*.name' => 'required|string',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.price' => 'required|integer|min:0',
            ]);

            $today = now()->toDateString();
            $reservation = Reservation::create([
                'branch_id' => $outlet->branch_id,
                'currency_code' => $outlet->branch->currency_code,
                'room_type_id' => null,
                'confirmation_number' => 'WL-'.Str::upper(Str::random(8)),
                'status' => 'checked_in',
                'source' => 'walk_in',
                'guest_name' => $request->string('guest_title').' '.$request->string('guest_name'),
                'check_in_date' => $today,
                'check_out_date' => $today,
                'room_rate' => 0,
                'adults' => 1,
                'children' => 0,
            ]);
        } else {
            $request->validate([
                'reservation_id' => 'required|exists:reservations,id',
                'items' => 'required|array|min:1',
                'items.*.menu_item_id' => 'nullable|integer|exists:menu_items,id',
                'items.*.name' => 'required|string',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.price' => 'required|integer|min:0',
            ]);

            $reservation = Reservation::findOrFail($request->integer('reservation_id'));

            if ($reservation->status !== 'checked_in') {
                return back()->withErrors(['reservation_id' => 'Reservation must be checked in.']);
            }
        }

        $user = $request->user();
        abort_unless($user !== null, 401);

        /** @var list<array{menu_item_id: int|null, name: string, quantity: int, price: int}> $items */
        $items = array_values((array) $request->input('items'));
        $subtotal = (int) array_sum(array_map(fn (array $item) => $item['price'] * $item['quantity'], $items));
        $taxRate = $outlet->branch->tax_rate / 100;
        $taxAmount = (int) ($subtotal * $taxRate);
        $total = $subtotal + $taxAmount;

        $folio = $reservation->folios()->where('status', 'open')->first();

        if (! $folio) {
            $folioService = new FolioService;
            $folio = $folioService->createFolio($outlet->branch->id, $reservation->id);
        }

        $paymentGuard = new PaymentGuardService;
        $posCharge = $paymentGuard->createAndDispatchCharge(
            $outlet->branch->id,
            $reservation->id,
            $folio->id,
            $outlet->code,
            $items,
            $subtotal,
            $taxAmount,
            $total
        );

        /** @var list<array{menu_item_id?: int, name: string, quantity: int, price: int}> $routableItems */
        $routableItems = array_values(array_filter($items, fn (array $item) => ! empty($item['menu_item_id'])));

        $routingService = new KotRoutingService;
        $routedItems = $routingService->routeItems($routableItems, $outlet->branch->id);

        foreach ($routedItems as $routedItem) {
            KotItem::create([
                'pos_charge_id' => $posCharge->id,
                'branch_id' => $outlet->branch->id,
                'outlet' => $routedItem['outlet'],
                'item_name' => $routedItem['name'],
                'quantity' => $routedItem['quantity'],
                'status' => 'pending',
                'priority' => 'normal',
            ]);
        }

        $inventoryService = new InventoryService;
        foreach ($items as $item) {
            if (! empty($item['menu_item_id'])) {
                $menuItem = MenuItem::find($item['menu_item_id']);
                if ($menuItem) {
                    $inventoryService->deductIngredients($menuItem, $item['quantity']);
                }
            }
        }

        $roomNumber = $reservation->room->number ?? 'Walk-in';

        return $this->flashSuccess('Charge posted to room '.$roomNumber);
    }
}
