<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Guest;
use App\Models\Reservation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CrsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branches = Branch::active()->get();
        $reservations = Reservation::whereIn('branch_id', $user->branches()->pluck('branches.id'))
            ->with(['branch', 'room'])
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('crs/Index', [
            'branches' => $branches,
            'reservations' => $reservations,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'guest_name' => 'required|string|max:255',
            'guest_email' => 'required|email',
            'guest_phone' => 'nullable|string|max:50',
            'room_type_id' => 'required|exists:room_types,id',
            'check_in_date' => 'required|date',
            'check_out_date' => 'required|date|after:check_in_date',
            'adults' => 'required|integer|min:1',
            'children' => 'integer|min:0',
            'room_rate' => 'required|integer|min:0',
            'special_requests' => 'nullable|string',
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = $request->integer('branch_id');
        $branch = Branch::find($branchId);

        if (! $branch || ! $user->hasAccessToBranch($branch)) {
            abort(403, 'You do not have access to this branch.');
        }

        $guest = Guest::firstOrCreate(
            ['email' => $request->string('guest_email')->value()],
            [
                'first_name' => explode(' ', $request->string('guest_name')->value())[0],
                'last_name' => implode(' ', array_slice(explode(' ', $request->string('guest_name')->value()), 1)) ?: '',
                'phone' => $request->string('guest_phone')->value() ?: null,
            ]
        );

        $reservation = Reservation::create([
            'branch_id' => $branchId,
            'currency_code' => $branch->currency_code,
            'guest_id' => $guest->id,
            'room_type_id' => $request->integer('room_type_id'),
            'guest_name' => $request->string('guest_name')->value(),
            'guest_email' => $request->string('guest_email')->value(),
            'guest_phone' => $request->string('guest_phone')->value() ?: null,
            'check_in_date' => $request->string('check_in_date')->value(),
            'check_out_date' => $request->string('check_out_date')->value(),
            'adults' => $request->integer('adults'),
            'children' => $request->integer('children', 0),
            'room_rate' => $request->integer('room_rate'),
            'total_amount' => $request->integer('room_rate'),
            'status' => 'confirmed',
            'source' => 'crs',
            'special_requests' => $request->input('special_requests') ? ['notes' => $request->string('special_requests')->value()] : null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Reservation created: '.$reservation->confirmation_number]);

        return redirect()->route('crs.index');
    }
}
