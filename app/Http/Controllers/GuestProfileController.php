<?php

namespace App\Http\Controllers;

use App\Models\DoNotRent;
use App\Models\Guest;
use App\Services\GuestDedupService;
use App\Services\IdentityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class GuestProfileController extends Controller
{
    public function show(Guest $guest): Response|RedirectResponse
    {
        // Retired profiles redirect to the survivor forever.
        if ($guest->master_guest_id !== null) {
            return redirect()->route('guests.profile', ['guest' => $guest->master_guest_id]);
        }

        $guest->load(['reservations' => fn (Builder $q) => $q->orderByDesc('check_in_date')->limit(25), 'identityDocuments', 'preferences']);

        $documents = $guest->identityDocuments->map(fn ($doc) => [
            'id' => $doc->id,
            'doc_type' => $doc->doc_type,
            'masked_number' => IdentityService::mask($doc->doc_number),
            'ocr_status' => $doc->ocr_status,
            'has_scan' => $doc->scan_path !== null,
            'created_at' => $doc->created_at,
        ])->values();

        return Inertia::render('guests/Profile', [
            'guest' => $guest,
            'documents' => $documents,
            'candidates' => (new GuestDedupService)->suggest($guest)->values(),
            'dnr' => DoNotRent::where('guest_id', $guest->id)->orderByDesc('id')->limit(10)->get(),
        ]);
    }
}
