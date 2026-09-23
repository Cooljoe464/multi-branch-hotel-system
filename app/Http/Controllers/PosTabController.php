<?php

namespace App\Http\Controllers;

use App\Exceptions\AvailabilityException;
use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\DiningTable;
use App\Models\HappyHour;
use App\Models\Outlet;
use App\Models\PosCharge;
use App\Models\PosModifier;
use App\Models\Reservation;
use App\Services\PosService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PosTabController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Outlet $outlet): Response
    {
        $this->ensureBranchAccess($outlet->branch);

        return Inertia::render('pos/Floor', [
            'outlet' => $outlet,
            'tables' => DiningTable::forOutlet($outlet->id)->orderBy('code')->get(),
            'tabs' => PosCharge::forBranch($outlet->branch_id)
                ->forOutlet($outlet->code)
                ->where('status', 'pending')
                ->with(['diningTable', 'reservation'])
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
            'modifiers' => PosModifier::forBranch($outlet->branch_id)->active()->orderBy('name')->get(),
            'happyHours' => HappyHour::forOutlet($outlet->id)->orderBy('id')->get(),
        ]);
    }

    public function open(Request $request, Outlet $outlet): RedirectResponse
    {
        $this->ensureBranchAccess($outlet->branch);

        $request->validate([
            'reservation_id' => 'required|exists:reservations,id',
            'dining_table_id' => 'nullable|exists:dining_tables,id',
            'covers' => 'nullable|integer|min:1|max:100',
        ]);

        $reservation = Reservation::findOrFail($request->integer('reservation_id'));

        $table = null;
        if ($request->filled('dining_table_id')) {
            $table = DiningTable::findOrFail($request->integer('dining_table_id'));
        }

        try {
            $tab = (new PosService)->openTab(
                $outlet->branch,
                $outlet,
                $reservation,
                $table,
                $request->integer('covers', 1),
                $request->user(),
            );
        } catch (AvailabilityException $e) {
            return back()->withErrors(['tab' => $e->getMessage()]);
        }

        return $this->flashSuccess("Tab #{$tab->id} opened.");
    }

    public function addItems(Request $request, PosCharge $charge): RedirectResponse
    {
        $this->ensureBranchAccess($charge->branch);

        $request->validate([
            'items' => 'required|array|min:1|max:50',
            'items.*.menu_item_id' => 'nullable|integer|exists:menu_items,id',
            'items.*.name' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1|max:100',
            'items.*.price' => 'required|integer|min:0',
            'items.*.modifier_ids' => 'nullable|array|max:10',
            'items.*.modifier_ids.*' => 'integer|min:1',
            'items.*.course' => 'nullable|string|in:starter,main,dessert',
        ]);

        $rows = $request->input('items');
        $clean = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $line = [];
                foreach ($row as $key => $value) {
                    if (is_string($key)) {
                        $line[$key] = $value;
                    }
                }
                $clean[] = $line;
            }
        }

        try {
            (new PosService)->addItems($charge, $clean, $request->user());
        } catch (AvailabilityException $e) {
            return back()->withErrors(['items' => $e->getMessage()]);
        }

        return $this->flashSuccess('Lines added; happy hour frozen.');
    }

    public function fire(Request $request, PosCharge $charge): RedirectResponse
    {
        $this->ensureBranchAccess($charge->branch);

        $request->validate([
            'course' => 'required|string|in:starter,main,dessert',
        ]);

        try {
            $kots = (new PosService)->fireCourse($charge, $request->string('course')->value(), $request->user());
        } catch (AvailabilityException $e) {
            return back()->withErrors(['course' => $e->getMessage()]);
        }

        return $this->flashSuccess(count($kots).' KOT lines fired.');
    }

    public function split(Request $request, PosCharge $charge): RedirectResponse
    {
        $this->ensureBranchAccess($charge->branch);

        $request->validate([
            'legs' => 'required|array|min:1|max:20',
            'legs.*.percent_bps' => 'nullable|integer|min:1|max:10000',
            'legs.*.amount_minor' => 'nullable|integer|min:0',
            'legs.*.item_indexes' => 'nullable|array|max:50',
            'legs.*.item_indexes.*' => 'integer|min:0',
        ]);

        $raw = $request->input('legs');
        $legs = [];
        if (is_array($raw)) {
            foreach ($raw as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $leg = [];
                foreach ($row as $key => $value) {
                    if (is_string($key)) {
                        $leg[$key] = $value;
                    }
                }
                $legs[] = $leg;
            }
        }

        try {
            $children = (new PosService)->splitBill($charge, $legs, $request->user());
        } catch (AvailabilityException $e) {
            return back()->withErrors(['legs' => $e->getMessage()]);
        }

        return $this->flashSuccess('Split into '.count($children).' bills; shares sum exactly.');
    }

    public function post(Request $request, PosCharge $charge): RedirectResponse
    {
        $this->ensureBranchAccess($charge->branch);

        $user = $request->user();
        abort_unless($user !== null, 401);

        try {
            (new PosService)->postTab($charge, $user->id);
        } catch (AvailabilityException $e) {
            return back()->withErrors(['tab' => $e->getMessage()]);
        }

        return $this->flashSuccess('Tab posted to the folio.');
    }

    public function replayOffline(Request $request, Outlet $outlet): RedirectResponse
    {
        $this->ensureBranchAccess($outlet->branch);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $request->validate([
            'payloads' => 'required|array|min:1|max:100',
        ]);

        $raw = $request->input('payloads');
        $payloads = [];
        if (is_array($raw)) {
            foreach ($raw as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $payload = [];
                foreach ($row as $key => $value) {
                    if (is_string($key)) {
                        $payload[$key] = $value;
                    }
                }
                $payloads[] = $payload;
            }
        }

        $result = (new PosService)->replayOffline($outlet, $payloads, $user);

        if ($result['errors'] !== []) {
            return back()->withErrors(['offline' => implode(' ', array_slice($result['errors'], 0, 3))]);
        }

        return $this->flashSuccess("Offline queue synced: {$result['posted']} posted, {$result['skipped']} already present.");
    }
}
