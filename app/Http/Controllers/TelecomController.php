<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\CallRecord;
use App\Models\HotspotTier;
use App\Models\MobileKey;
use App\Models\Reservation;
use App\Models\TelecomRate;
use App\Models\WifiSession;
use App\Services\WifiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TelecomController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Branch $branch, Request $request): Response
    {
        $this->ensureBranchAccess($branch);

        $lookup = null;
        $confirmation = $request->string('confirmation')->value();

        if ($confirmation !== '') {
            $reservation = Reservation::forBranch($branch->id)->where('confirmation_number', $confirmation)->first();

            if ($reservation) {
                $lookup = [
                    'id' => $reservation->id,
                    'confirmation_number' => $reservation->confirmation_number,
                    'guest_name' => $reservation->guest_name,
                    'status' => $reservation->status,
                    'keys' => MobileKey::where('reservation_id', $reservation->id)->latest('id')->limit(10)->get(['id', 'device_id', 'status', 'valid_to'])->map(fn (MobileKey $k) => [
                        'id' => $k->id,
                        'device_id' => $k->device_id,
                        'status' => $k->status,
                        'valid_to' => $k->valid_to?->toIso8601String(),
                    ])->all(),
                ];
            }
        }

        return Inertia::render('front-desk/Connectivity', [
            'branch' => $branch->only(['id', 'name']),
            'rates' => TelecomRate::where('branch_id', $branch->id)->orderBy('destination_prefix')->get(['id', 'destination_prefix', 'rate_minor_per_min', 'is_active']),
            'calls' => CallRecord::where('branch_id', $branch->id)->latest('id')->limit(50)->get(['id', 'extension', 'destination', 'duration_secs', 'charge_minor', 'reservation_id', 'cdr_id', 'created_at']),
            'vouchers' => WifiSession::where('branch_id', $branch->id)->with('tier:id,name,code')->latest('id')->limit(50)->get(['id', 'voucher', 'username', 'reservation_id', 'hotspot_tier_id', 'provisioned_at', 'expires_at', 'revoked_at']),
            'hotspot_tiers' => HotspotTier::forBranch($branch->id)->active()->orderBy('price_minor')->get(['id', 'name', 'code', 'price_minor']),
            'cdr_configured' => is_string($branch->cdr_secret) && $branch->cdr_secret !== '',
            'lookup' => $lookup,
            'confirmation' => $confirmation,
        ]);
    }

    public function storeRate(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'destination_prefix' => 'required|string|max:16',
            'rate_minor_per_min' => 'required|integer|min:0',
        ]);

        TelecomRate::updateOrCreate(
            ['branch_id' => $branch->id, 'destination_prefix' => $request->string('destination_prefix')->value()],
            ['rate_minor_per_min' => $request->integer('rate_minor_per_min'), 'is_active' => true],
        );

        return redirect()->route('connectivity.index', $branch)
            ->with('toast', ['type' => 'success', 'message' => 'Rate saved.']);
    }

    public function storeSecret(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate(['cdr_secret' => 'required|string|min:32|max:128']);

        $branch->update(['cdr_secret' => $request->string('cdr_secret')->value()]);

        return redirect()->route('connectivity.index', $branch)
            ->with('toast', ['type' => 'success', 'message' => 'CDR secret saved. Configure the PBX to sign with it.']);
    }

    public function issueVoucher(Request $request, Branch $branch, WifiService $wifi): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'confirmation_number' => 'required|string|max:32',
            'guest_name' => 'required|string|max:128',
        ]);

        $wifi->issue($branch, $request->string('confirmation_number')->value(), $request->string('guest_name')->value());

        return redirect()->route('connectivity.index', $branch)
            ->with('toast', ['type' => 'success', 'message' => 'Voucher issued. Print it for the guest.']);
    }

    public function revokeVoucher(Branch $branch, WifiSession $session, WifiService $wifi): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($session->branch_id === $branch->id, 404);

        $wifi->revoke($session);

        return redirect()->route('connectivity.index', $branch)
            ->with('toast', ['type' => 'success', 'message' => 'Voucher revoked.']);
    }
}
