<?php

namespace App\Http\Controllers;

use App\Models\Folio;
use App\Models\LaundryOrder;
use App\Services\FolioService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class LaundryController extends Controller
{
    use Concerns\EnsuresBranchAccess;

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

        $orders = LaundryOrder::forBranch($branchId)
            ->with(['reservation.room', 'attendant'])
            ->when($request->filled('status'), fn ($q) => $q->forStatus($request->string('status')->value()))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('laundry/Index', [
            'laundryOrders' => $orders,
            'filters' => $request->only(['status']),
        ]);
    }

    public function show(LaundryOrder $laundryOrder): Response
    {
        $user = request()->user();
        abort_unless($user !== null, 401);

        $this->ensureBranchAccess($laundryOrder->branch);

        return Inertia::render('laundry/Show', [
            'laundryOrder' => $laundryOrder->load(['reservation.room', 'attendant']),
        ]);
    }

    public function pickup(Request $request, LaundryOrder $laundryOrder): RedirectResponse
    {
        $this->ensureBranchAccess($laundryOrder->branch);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $laundryOrder->update([
            'status' => 'picked_up',
            'attendant_id' => $user->id,
            'pickup_window' => now(),
        ]);

        return $this->flashSuccess('Laundry order picked up.');
    }

    public function deliver(Request $request, LaundryOrder $laundryOrder): RedirectResponse
    {
        $this->ensureBranchAccess($laundryOrder->branch);

        $laundryOrder->update([
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);

        if ($laundryOrder->reservation_id) {
            try {
                $folio = Folio::where('reservation_id', $laundryOrder->reservation_id)->first();
                if ($folio && $folio->status === 'open') {
                    $totalCharge = $this->calculateLaundryCharge($laundryOrder);
                    if ($totalCharge > 0) {
                        $folioService = new FolioService;
                        $folioService->postDebit(
                            $folio,
                            'laundry',
                            'Laundry service: '.$this->buildLaundryDescription($laundryOrder),
                            $totalCharge,
                            null,
                            null,
                            LaundryOrder::class,
                            $laundryOrder->id,
                        );
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Laundry folio charge failed', [
                    'laundry_order' => $laundryOrder->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->flashSuccess('Laundry order delivered.');
    }

    public function verify(Request $request, LaundryOrder $laundryOrder): RedirectResponse
    {
        $this->ensureBranchAccess($laundryOrder->branch);

        $request->validate([
            'items' => 'required|array',
            'items.*.type' => 'required|string',
            'items.*.count' => 'required|integer|min:0',
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $laundryOrder->update([
            'items' => $request->input('items'),
            'metadata' => array_merge($laundryOrder->metadata ?? [], [
                'verified_at' => now()->toIso8601String(),
                'verified_by' => $user->id,
            ]),
        ]);

        return $this->flashSuccess('Laundry items verified.');
    }

    private function calculateLaundryCharge(LaundryOrder $order): int
    {
        $rates = [
            'shirt' => 1000,
            'suit' => 3000,
            'towel' => 500,
            'dress' => 2500,
            'pants' => 800,
            'default' => 1000,
        ];

        $total = 0;
        foreach ($order->items as $item) {
            $type = strtolower($item['type']);
            $count = (int) $item['count'];
            $rate = $rates[$type] ?? $rates['default'];
            $total += $rate * $count;
        }

        return $total;
    }

    private function buildLaundryDescription(LaundryOrder $order): string
    {
        $descriptions = [];
        foreach ($order->items as $item) {
            $type = $item['type'];
            $count = (int) $item['count'];
            if ($count > 0) {
                $descriptions[] = "{$count}x {$type}";
            }
        }

        return implode(', ', $descriptions) ?: 'Laundry order #'.$order->id;
    }
}
