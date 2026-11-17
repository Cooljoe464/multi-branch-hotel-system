<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\OverbookingAllowance;
use App\Models\RoomType;
use App\Models\User;
use App\Services\OverbookingAllowanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OverbookingAllowanceController extends Controller
{
    public function store(Request $request, Branch $branch, OverbookingAllowanceService $allowances): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->hasEnabledTwoFactorAuthentication(), 403, 'Confirmed two-factor authentication is required to set overbooking allowances.');

        $validated = $request->validate([
            'room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'allowed_count' => ['required', 'integer', 'min:1'],
            'allowed_sources' => ['required', 'array', 'min:1'],
            'allowed_sources.*' => ['required', 'string', 'in:front_desk,public_api,ota'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $roomType = RoomType::query()->where('branch_id', $branch->id)->findOrFail($request->integer('room_type_id'));
        $sources = $request->input('allowed_sources');
        abort_unless(is_array($sources), 422);
        $allowedSources = array_values(array_filter($sources, static fn (mixed $source): bool => is_string($source)));

        $allowances->create(
            $branch,
            $roomType,
            $request->string('starts_on')->value(),
            $request->string('ends_on')->value(),
            $request->integer('allowed_count'),
            $allowedSources,
            $request->string('reason')->value(),
            $actor,
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Overbooking allowance created.']);
    }

    public function destroy(Request $request, Branch $branch, OverbookingAllowance $allowance, OverbookingAllowanceService $allowances): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->hasEnabledTwoFactorAuthentication(), 403, 'Confirmed two-factor authentication is required to revoke overbooking allowances.');
        abort_unless($allowance->branch_id === $branch->id, 404);

        $allowances->revoke($allowance, $actor);

        return back()->with('toast', ['type' => 'success', 'message' => 'Overbooking allowance revoked.']);
    }
}
