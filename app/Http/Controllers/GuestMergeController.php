<?php

namespace App\Http\Controllers;

use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\Guest;
use App\Services\GuestDedupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GuestMergeController extends Controller
{
    public function index(Request $request, Branch $branch): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        return Inertia::render('guests/Merges', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'candidates' => (new GuestDedupService)->candidates(100),
        ]);
    }

    public function suggest(Guest $guest): JsonResponse
    {
        $matches = (new GuestDedupService)->suggest($guest);

        return response()->json([
            'guest_id' => $guest->id,
            'candidates' => $matches->map(fn (Guest $g) => [
                'id' => $g->id,
                'name' => $g->full_name,
                'email' => $g->email,
                'stays' => $g->total_stays,
            ])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $request->validate([
            'surviving_guest_id' => 'required|exists:guests,id',
            'retired_guest_id' => 'required|exists:guests,id|different:surviving_guest_id',
            'field_choices' => 'nullable|array',
            'field_choices.*' => 'in:survivor,retired',
        ]);

        $survivor = Guest::findOrFail($request->integer('surviving_guest_id'));
        $retired = Guest::findOrFail($request->integer('retired_guest_id'));

        $choices = $request->input('field_choices');
        $fieldChoices = [];
        if (is_array($choices)) {
            foreach ($choices as $field => $choice) {
                if (is_string($field) && ($choice === 'survivor' || $choice === 'retired')) {
                    $fieldChoices[$field] = $choice;
                }
            }
        }

        try {
            $link = (new GuestDedupService)->merge($survivor, $retired, $fieldChoices, $user);
        } catch (AvailabilityException $e) {
            return back()->withErrors(['merge' => $e->getMessage()]);
        }

        return redirect()->route('guests.profile', ['guest' => $link->surviving_guest_id])
            ->with('toast', ['type' => 'success', 'message' => 'Profiles merged; history now lives on the survivor.']);
    }
}
