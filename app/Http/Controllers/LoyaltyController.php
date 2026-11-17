<?php

namespace App\Http\Controllers;

use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\Consent;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTier;
use App\Models\Reservation;
use App\Services\LoyaltyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class LoyaltyController extends Controller
{
    public function index(Request $request, Branch $branch): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $tierMix = LoyaltyAccount::whereHas('guest.reservations', fn ($q) => $q->where('branch_id', $branch->id))
            ->selectRaw('tier, COUNT(*) as accounts, SUM(points) as points')
            ->groupBy('tier')
            ->get();

        $consentCoverage = 0;
        $guestCount = 0;
        if (Schema::hasTable('consents')) {
            $guestIds = Reservation::forBranch($branch->id)->distinct()->pluck('guest_id')->filter();
            $guestCount = count($guestIds);
            $consentCoverage = $guestCount > 0
                ? Consent::whereIn('guest_id', $guestIds)->distinct('guest_id')->count('guest_id')
                : 0;
        }

        return Inertia::render('crm/Dashboard', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'tiers' => LoyaltyTier::orderBy('threshold_nights')->get(),
            'canManageTiers' => $user->is_global_admin === true,
            'tierMix' => $tierMix,
            'consentCoverage' => ['guests' => $guestCount, 'consented' => $consentCoverage],
            'recent' => LoyaltyAccount::with('guest')
                ->orderByDesc('updated_at')
                ->limit(25)
                ->get(),
        ]);
    }

    /**
     * The tier ladder is shared by every property, so only platform
     * owners may change it. Saving an existing name updates that tier.
     */
    public function saveTier(Request $request, Branch $branch): RedirectResponse
    {
        abort_unless($request->user()?->is_global_admin === true, 403);

        $data = $request->validate([
            'name' => 'required|string|max:32|regex:/^[a-z0-9_-]+$/',
            'threshold_nights' => 'required|integer|min:0|max:100000',
            'earn_bps' => 'required|integer|min:0|max:100000',
        ]);

        LoyaltyTier::updateOrCreate(
            ['name' => $data['name']],
            ['threshold_nights' => $data['threshold_nights'], 'earn_bps' => $data['earn_bps']],
        );

        return $this->flashSuccess("Loyalty tier {$data['name']} saved.");
    }

    public function destroyTier(Request $request, Branch $branch, LoyaltyTier $tier): RedirectResponse
    {
        abort_unless($request->user()?->is_global_admin === true, 403);
        abort_if($tier->name === 'member', 422, 'The base member tier cannot be removed.');

        $tier->delete();

        return $this->flashSuccess("Loyalty tier {$tier->name} removed.");
    }

    public function enroll(Request $request, Branch $branch): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $request->validate([
            'guest_id' => 'required|exists:guests,id',
        ]);

        $guest = Guest::findOrFail($request->integer('guest_id'));

        (new LoyaltyService)->enroll($guest);

        return $this->flashSuccess("{$guest->full_name} enrolled at member tier.");
    }

    public function redeem(Request $request, Branch $branch): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $request->validate([
            'guest_id' => 'required|exists:guests,id',
            'folio_id' => 'required|exists:folios,id',
            'points' => 'required|integer|min:1',
        ]);

        $account = LoyaltyAccount::where('guest_id', $request->integer('guest_id'))->firstOrFail();
        $folio = Folio::findOrFail($request->integer('folio_id'));
        abort_unless($folio->branch_id === $branch->id, 403);

        $key = $request->header('X-Idempotency-Key');
        $key = is_string($key) && trim($key) !== '' ? trim($key) : null;

        try {
            (new LoyaltyService)->redeem($account, $folio, $request->integer('points'), $user, $key);
        } catch (AvailabilityException $e) {
            return back()->withErrors(['points' => $e->getMessage()]);
        }

        return $this->flashSuccess('Points redeemed as folio credit.');
    }
}
