<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use App\Models\Reservation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GuestPortalController extends Controller
{
    public function folio(string $confirmationNumber): Response
    {
        $reservation = Reservation::where('confirmation_number', $confirmationNumber)
            ->with(['branch', 'room', 'roomType', 'guest'])
            ->firstOrFail();

        return Inertia::render('guest/Folio', [
            'reservation' => $reservation,
            'guest' => $reservation->guest,
        ]);
    }

    public function searchGuest(Request $request): Response
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'confirmation' => 'required|string',
        ]);

        $reservation = Reservation::where('confirmation_number', $validated['confirmation'])
            ->where('guest_email', $validated['email'])
            ->with(['branch', 'room', 'roomType', 'guest'])
            ->first();

        if (! $reservation) {
            return back()->withErrors([
                'email' => 'No reservation found with this email and confirmation number.',
            ]);
        }

        return Inertia::render('guest/Folio', [
            'reservation' => $reservation,
            'guest' => $reservation->guest,
        ]);
    }

    public function guestProfile(string $email): ?Response
    {
        $guest = Guest::where('email', $email)->first();

        if (! $guest) {
            return null;
        }

        $reservations = $guest->reservations()
            ->with(['branch', 'roomType'])
            ->orderBy('check_in_date', 'desc')
            ->get();

        return Inertia::render('guest/Profile', [
            'guest' => $guest,
            'preferences' => $guest->preferences,
            'reservations' => $reservations,
        ]);
    }
}
