<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GuestPortalController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('guest/Index', [
            'defaultLocale' => Branch::active()->primary()->value('locale') ?? 'en',
        ]);
    }

    public function folio(string $confirmationNumber): Response
    {
        $reservation = Reservation::where('confirmation_number', $confirmationNumber)
            ->with(['branch', 'room', 'roomType', 'guest'])
            ->firstOrFail();

        $folio = Folio::whereHas('reservation', function ($q) use ($confirmationNumber) {
            $q->where('confirmation_number', $confirmationNumber);
        })
            ->with([
                'reservation.room',
                'reservation.roomType',
                'reservation.branch',
                'transactions',
            ])
            ->first();

        if (! $folio) {
            return Inertia::render('guest/Folio', [
                'folio' => null,
                'reservation' => $reservation,
                'guest' => $reservation->guest,
                'transactions' => collect(),
            ]);
        }

        return Inertia::render('guest/Folio', [
            'folio' => $folio,
            'reservation' => $reservation,
            'guest' => $reservation->guest,
            'transactions' => $folio->transactions->sortByDesc('created_at')->values(),
        ]);
    }

    public function searchGuest(Request $request): Response
    {
        $request->validate([
            'email' => 'required|email',
            'confirmation' => 'required|string',
        ]);

        $confirmation = $request->string('confirmation')->value();
        $email = $request->string('email')->value();

        $reservation = Reservation::where('confirmation_number', $confirmation)
            ->where('guest_email', $email)
            ->with(['branch', 'room', 'roomType', 'guest'])
            ->first();

        if (! $reservation) {
            throw ValidationException::withMessages([
                'email' => 'No reservation found with this email and confirmation number.',
            ]);
        }

        return Inertia::render('guest/Folio', [
            'folio' => null,
            'reservation' => $reservation,
            'guest' => $reservation->guest,
            'transactions' => collect(),
        ]);
    }

    public function guestProfile(string $email): Response
    {
        $guest = Guest::where('email', $email)->first();

        if (! $guest) {
            abort(404, 'Guest not found.');
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
