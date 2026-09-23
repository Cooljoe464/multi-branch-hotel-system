<?php

namespace App\Http\Controllers;

use App\Exceptions\AvailabilityException;
use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Reservation;
use App\Models\UpsellOffer;
use App\Services\UpsellService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UpsellAcceptanceController extends Controller
{
    use EnsuresBranchAccess;

    public function quote(Request $request, Reservation $reservation): JsonResponse
    {
        $reservation->load(['branch']);

        $this->ensureBranchAccess($reservation->branch);

        $quotes = (new UpsellService)->quote($reservation->branch, $reservation, true);

        return response()->json(['quotes' => $quotes]);
    }

    public function accept(Request $request, Reservation $reservation): RedirectResponse
    {
        $reservation->load(['branch']);

        $this->ensureBranchAccess($reservation->branch);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $request->validate([
            'offer_id' => 'required|exists:upsell_offers,id',
            'grant_free' => 'nullable|boolean',
            'expires_at' => 'nullable|date',
        ]);

        $free = $request->boolean('grant_free');

        if ($free && ! ($user->can('upsell.grant_free') || (bool) ($user->is_global_admin ?? false))) {
            abort(403, 'Free grants require the upsell.grant_free permission.');
        }

        $offer = UpsellOffer::findOrFail($request->integer('offer_id'));

        $key = $request->header('X-Idempotency-Key');
        $key = is_string($key) && trim($key) !== '' ? trim($key) : null;
        abort_unless($key !== null, 422, 'Upsell acceptance requires an X-Idempotency-Key header.');

        $expires = $request->string('expires_at')->value();
        $expires = $expires !== '' ? $expires : null;

        try {
            (new UpsellService)->accept($reservation, $offer, $user, $key, $free, $expires);
        } catch (AvailabilityException $e) {
            if ($e->availabilityCode === 'UPSELL_EXPIRED') {
                abort(410, $e->getMessage());
            }

            return back()->withErrors(['offer' => $e->getMessage()]);
        }

        return $this->flashSuccess($free ? 'Upsell granted free (logged).' : 'Upsell accepted and posted to the folio.');
    }
}
