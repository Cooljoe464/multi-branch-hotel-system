<?php

namespace App\Http\Controllers;

use App\Models\RegistrationCard;
use App\Models\Reservation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationCardController extends Controller
{
    use Concerns\EnsuresBranchAccess;

    public function show(Reservation $reservation): Response
    {
        $this->ensureBranchAccess($reservation->branch);

        $card = $reservation->registrationCard;

        return Inertia::render('reservations/RegistrationCard', [
            'reservation' => $reservation,
            'registrationCard' => $card,
        ]);
    }

    public function store(Request $request, Reservation $reservation): RedirectResponse
    {
        $this->ensureBranchAccess($reservation->branch);

        $request->validate([
            'guest_name' => 'required|string|max:255',
            'id_type' => 'required|string|in:passport,drivers_license,national_id',
            'id_number' => 'required|string|max:100',
            'id_image_url' => 'nullable|string',
            'signature_image_url' => 'required|string',
        ]);

        $card = RegistrationCard::updateOrCreate(
            ['reservation_id' => $reservation->id],
            [
                'guest_name' => $request->string('guest_name')->value(),
                'id_type' => $request->string('id_type')->value(),
                'id_number' => $request->string('id_number')->value(),
                'id_image_url' => $request->string('id_image_url')->value() ?: null,
                'signature_image_url' => $request->string('signature_image_url')->value(),
                'signed_at' => now(),
            ]
        );

        return $this->flashSuccess('Registration card saved.');
    }
}
