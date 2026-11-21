<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Reservation;
use App\Services\IdentityService;
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

    /**
     * HMAC-signed self check-in token for a reservation. The subject binds the
     * confirmation number and expiry into the signature, so the link cannot be
     * forged without the application key and dies once expired.
     */
    public function checkinToken(Reservation $reservation): string
    {
        $expiresAt = ($reservation->check_in_date ?? now())->addDay()->startOfDay();

        return hash_hmac('sha256', 'hms-checkin:'.$reservation->confirmation_number.':'.$expiresAt->toDateString(), (string) config('app.key'));
    }

    public function checkinTokenValid(Reservation $reservation, ?string $token): bool
    {
        if (! is_string($token) || $token === '') {
            return false;
        }

        return hash_equals($this->checkinToken($reservation), $token);
    }

    public function checkin(Request $request, string $confirmationNumber): Response
    {
        $reservation = Reservation::where('confirmation_number', $confirmationNumber)
            ->with(['branch', 'room', 'roomType', 'guest'])
            ->firstOrFail();

        if (! $this->checkinTokenValid($reservation, $request->input('token') ?? $request->query('token'))) {
            abort(403, 'Invalid or expired check-in link.');
        }

        return Inertia::render('guest/Checkin', [
            'branch' => $reservation->branch->only(['id', 'name', 'currency_code']),
            'room' => $reservation->room?->only(['id', 'number', 'status']),
            'roomType' => $reservation->roomType?->only(['id', 'name', 'code']),
            'reservation' => [
                'confirmation_number' => $reservation->confirmation_number,
                'status' => $reservation->status,
                'check_in_date' => $reservation->check_in_date?->toDateString(),
                'check_out_date' => $reservation->check_out_date?->toDateString(),
                'guest_name' => $reservation->guest_name,
                'guest_email' => $reservation->guest_email,
                'adults' => $reservation->adults,
                'children' => $reservation->children,
                'total_amount' => $reservation->total_amount,
            ],
            'guest' => $reservation->guest?->only([
                'id',
                'first_name',
                'last_name',
                'email',
                'phone',
                'id_type',
                'id_number',
            ]),
        ]);
    }

    public function checkinSubmit(Request $request, string $confirmationNumber): Response
    {
        $reservation = Reservation::where('confirmation_number', $confirmationNumber)
            ->with(['branch', 'room', 'roomType', 'guest'])
            ->firstOrFail();

        if (! $this->checkinTokenValid($reservation, $request->input('token') ?? $request->query('token'))) {
            abort(403, 'Invalid or expired check-in link.');
        }

        $data = $request->validate([
            'eta' => ['nullable', 'date'],
            'vehicle' => ['nullable', 'string', 'max:64'],
            'signature' => ['nullable', 'string', 'max:255'],
            'id_type' => ['nullable', 'string', 'in:nin,passport,drivers'],
            'id_number' => ['nullable', 'string', 'max:64'],
            'id_scan' => ['nullable', 'file', 'mimes:jpeg,jpg,png,pdf', 'max:10240'],
        ]);

        if (! empty($data['id_type']) && $reservation->guest) {
            app(IdentityService::class)->capture(
                $reservation->guest,
                $data['id_type'],
                $data['id_number'] ?? null,
                $request->file('id_scan'),
            );
        }

        $reservation->update([
            'metadata' => array_merge($reservation->metadata ?? [], [
                'guest_self_checkin' => [
                    'eta' => $data['eta'] ?? null,
                    'vehicle' => $data['vehicle'] ?? null,
                    'signature' => $data['signature'] ?? null,
                    'id_type' => $data['id_type'] ?? null,
                    'submitted_at' => now()->toIso8601String(),
                ],
            ]),
        ]);

        return Inertia::render('guest/Checkin', [
            'branch' => $reservation->branch->only(['id', 'name', 'currency_code']),
            'room' => $reservation->room?->only(['id', 'number', 'status']),
            'roomType' => $reservation->roomType?->only(['id', 'name', 'code']),
            'reservation' => [
                'confirmation_number' => $reservation->confirmation_number,
                'status' => $reservation->status,
                'check_in_date' => $reservation->check_in_date?->toDateString(),
                'check_out_date' => $reservation->check_out_date?->toDateString(),
                'guest_name' => $reservation->guest_name,
                'guest_email' => $reservation->guest_email,
                'adults' => $reservation->adults,
                'children' => $reservation->children,
                'total_amount' => $reservation->total_amount,
            ],
            'guest' => $reservation->guest?->only([
                'id',
                'first_name',
                'last_name',
                'email',
                'phone',
                'id_type',
                'id_number',
            ]),
        ]);
    }
}
