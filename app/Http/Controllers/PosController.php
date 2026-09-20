<?php

namespace App\Http\Controllers;

use App\Models\Folio;
use App\Models\KotItem;
use App\Models\PosCharge;
use App\Models\Reservation;
use App\Services\FolioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosController extends Controller
{
    public function charge(Request $request): JsonResponse
    {
        $request->validate([
            'reservation_id' => 'required|integer|exists:reservations,id',
            'outlet' => 'required|string|max:100',
            'items' => 'required|array|min:1',
            'items.*.name' => 'required|string|max:255',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|integer|min:0',
        ]);

        $reservation = Reservation::with(['branch', 'room', 'folio'])->findOrFail($request->integer('reservation_id'));

        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $user->hasAccessToBranch($reservation->branch)) {
            abort(403, 'You do not have access to this property.');
        }

        if ($reservation->status !== 'checked_in') {
            return response()->json([
                'message' => 'Reservation must be checked in to post charges.',
            ], 422);
        }

        $outlet = $request->string('outlet')->value();
        /** @var array<int, array{name: string, qty: int, unit_price: int}> $itemData */
        $itemData = $request->input('items');
        $items = collect($itemData)->map(function (array $item) {
            $item['total'] = $item['qty'] * $item['unit_price'];

            return $item;
        });

        $subtotal = $items->reduce(fn (int $carry, array $item) => $carry + $item['total'], 0);
        $branch = $reservation->branch;
        $taxRateBps = (int) ($branch->tax_rate * 100);
        $taxAmount = (int) round($subtotal * $taxRateBps / 10000);
        $total = (int) ($subtotal + $taxAmount);

        /** @var Folio|null $folio */
        $folio = $reservation->folio;
        if (! $folio || $folio->status !== 'open') {
            $folioService = new FolioService;
            $folio = $folioService->createFolio($branch->id, $reservation->id, null, "Guest Folio: {$reservation->guest_name}");
        }

        $posCharge = PosCharge::create([
            'branch_id' => $branch->id,
            'currency_code' => $branch->currency_code,
            'reservation_id' => $reservation->id,
            'folio_id' => $folio->id,
            'outlet' => $outlet,
            'items' => $items->toArray(),
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'status' => 'pending',
        ]);

        $items->map(function ($item) use ($posCharge, $branch, $outlet) {
            return KotItem::create([
                'pos_charge_id' => $posCharge->id,
                'branch_id' => $branch->id,
                'outlet' => $outlet,
                'item_name' => $item['name'],
                'quantity' => $item['qty'],
                'status' => 'pending',
            ]);
        });

        $folioService = $folioService ?? new FolioService;
        $transaction = $folioService->postPosCharge($posCharge, $user->id);

        $posCharge->update([
            'transaction_id' => $transaction->id,
            'status' => 'posted',
            'posted_at' => now(),
        ]);

        return response()->json([
            'message' => 'Charge posted successfully.',
            'pos_charge' => $posCharge->fresh(['kotItems', 'folio', 'transaction']),
        ], 201);
    }
}
