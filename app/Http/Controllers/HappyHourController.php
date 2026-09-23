<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\HappyHour;
use App\Models\Outlet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HappyHourController extends Controller
{
    use EnsuresBranchAccess;

    public function store(Request $request, Outlet $outlet): RedirectResponse
    {
        $this->ensureBranchAccess($outlet->branch);

        $request->validate([
            'cron_window' => 'required|string|max:64',
            'discount_bps' => 'required|integer|min:0|max:10000',
            'menu_item_ids' => 'nullable|array|max:100',
            'menu_item_ids.*' => 'integer|min:1',
        ]);

        $ids = $request->input('menu_item_ids');
        $clean = [];
        if (is_array($ids)) {
            foreach ($ids as $id) {
                if (is_int($id)) {
                    $clean[] = $id;
                }
            }
        }

        HappyHour::create([
            'outlet_id' => $outlet->id,
            'branch_id' => $outlet->branch_id,
            'cron_window' => $request->string('cron_window')->value(),
            'discount_bps' => $request->integer('discount_bps'),
            'applies_to' => $clean === [] ? null : ['menu_item_ids' => $clean],
            'active' => true,
        ]);

        return $this->flashSuccess('Happy hour saved; priced server-side from here on.');
    }

    public function destroy(Outlet $outlet, HappyHour $happyHour): RedirectResponse
    {
        $this->ensureBranchAccess($outlet->branch);
        abort_unless($happyHour->outlet_id === $outlet->id, 404);

        $happyHour->delete();

        return $this->flashSuccess('Happy hour deleted. Frozen charges keep their snapshot.');
    }
}
