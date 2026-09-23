<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class GdprController extends Controller
{
    use Concerns\EnsuresBranchAccess;

    public function anonymize(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email|exists:guests,email',
        ]);

        $guest = Guest::where('email', $request->string('email')->value())->firstOrFail();

        // Verify the guest has reservations in the user's branch
        $user = $request->user();
        abort_unless($user !== null, 401);
        if (! $user->is_global_admin) {
            $hasAccess = $guest->reservations()
                ->where('branch_id', $user->branch_id)
                ->exists();
            abort_unless($hasAccess, 403, 'You do not have access to this guest.');
        }

        $hash = md5((string) now()->timestamp);
        $guest->update([
            'first_name' => 'Guest',
            'last_name' => 'Deleted',
            'email' => "{$hash}@deleted.local",
            'phone' => null,
            'id_type' => null,
            'id_number' => null,
            'company' => null,
            'dietary_restrictions' => null,
            'special_notes' => null,
            'internal_notes' => null,
            'metadata' => null,
        ]);

        $guest->delete();

        activity('gdpr')
            ->performedOn($guest)
            ->withProperties(['action' => 'anonymize'])
            ->log('Guest data anonymized per GDPR request');

        return $this->flashSuccess('Guest data anonymized and record soft-deleted.');
    }

    public function export(Request $request): Response|JsonResponse
    {
        $email = $request->string('email')->value();
        $guest = Guest::with(['preferences', 'reservations'])
            ->where('email', $email)
            ->orWhere('email', 'LIKE', '%@deleted.local')
            ->firstOrFail();

        $data = [
            'guest' => $guest->only([
                'first_name', 'last_name', 'email', 'phone', 'date_of_birth',
                'nationality', 'id_type', 'company', 'vip_status',
                'total_stays', 'total_nights', 'total_spent',
            ]),
            'preferences' => $guest->preferences()->get()->toArray(),
            'reservations' => $guest->reservations()->get()->map(fn ($r) => $r->only([
                'confirmation_number', 'check_in_date', 'check_out_date',
                'room_rate', 'total_amount', 'status', 'source',
            ])),
        ];

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="guest-export-'.$guest->id.'.json"',
        ]);
    }
}
