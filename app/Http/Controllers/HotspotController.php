<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\HotspotTier;
use App\Models\Reservation;
use App\Services\HotspotService;
use App\Services\RouterOsConfigService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HotspotController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        return Inertia::render('hotspot/Index', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'tiers' => HotspotTier::forBranch($branch->id)->orderBy('price_minor')->get(),
            'nas_configured' => $this->nasConfigured($branch),
        ]);
    }

    public function store(Request $request, Branch $branch, HotspotService $hotspot): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'name' => 'required|string|max:64',
            'code' => 'required|string|max:32',
            'price_minor' => 'required|integer|min:0',
            'rate_up_kbps' => 'required|integer|min:64|max:1000000',
            'rate_down_kbps' => 'required|integer|min:64|max:1000000',
            'quota_mb' => 'nullable|integer|min:0',
            'duration_mins' => 'nullable|integer|min:0',
            'device_limit' => 'required|integer|min:1|max:10',
        ]);

        HotspotTier::updateOrCreate(
            ['branch_id' => $branch->id, 'code' => $request->string('code')->value()],
            [
                'branch_id' => $branch->id,
                'name' => $request->string('name')->value(),
                'code' => $request->string('code')->value(),
                'price_minor' => $request->integer('price_minor'),
                'rate_up_kbps' => $request->integer('rate_up_kbps'),
                'rate_down_kbps' => $request->integer('rate_down_kbps'),
                'quota_mb' => $request->filled('quota_mb') ? $request->integer('quota_mb') : null,
                'duration_mins' => $request->filled('duration_mins') ? $request->integer('duration_mins') : null,
                'device_limit' => $request->integer('device_limit'),
                'is_active' => true,
            ],
        );

        return redirect()->route('hotspot.index', $branch)
            ->with('toast', ['type' => 'success', 'message' => 'Wi-Fi tier saved.']);
    }

    public function selectTier(Request $request, Reservation $reservation, HotspotService $hotspot): RedirectResponse
    {
        $this->ensureBranchAccess($reservation->branch);

        $request->validate(['hotspot_tier_id' => 'required|exists:hotspot_tiers,id']);

        $tier = HotspotTier::findOrFail($request->integer('hotspot_tier_id'));

        $headerKey = $request->header('X-Idempotency-Key');

        $hotspot->selectTier(
            $reservation,
            $tier,
            $request->user(),
            is_string($headerKey) ? $headerKey : Str::uuid()->toString(),
        );

        return redirect()->back()
            ->with('toast', ['type' => 'success', 'message' => "Wi-Fi tier set to {$tier->name}."]);
    }

    public function export(Branch $branch, RouterOsConfigService $exporter): StreamedResponse
    {
        $this->ensureBranchAccess($branch);

        $rsc = $exporter->export($branch);

        return response()->streamDownload(
            fn () => print ($rsc),
            "hotspot-b{$branch->id}.rsc",
            ['Content-Type' => 'text/plain'],
        );
    }

    private function nasConfigured(Branch $branch): bool
    {
        $settings = is_array($branch->settings) ? $branch->settings : [];

        return isset($settings['tunnel_ip']) || isset($settings['radius_host']);
    }
}
