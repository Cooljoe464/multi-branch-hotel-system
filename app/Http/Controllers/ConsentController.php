<?php

namespace App\Http\Controllers;

use App\Models\Consent;
use App\Models\Guest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ConsentController extends Controller
{
    /**
     * Record a consent decision. Latest timestamp wins; denials are
     * first-class rows, not deletions.
     */
    public function store(Request $request, Guest $guest): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $request->validate([
            'channel' => 'required|string|in:email,sms,whatsapp',
            'purpose' => 'required|string|max:64',
            'granted' => 'required|boolean',
        ]);

        Consent::create([
            'guest_id' => $guest->id,
            'channel' => $request->string('channel')->value(),
            'purpose' => $request->string('purpose')->value(),
            'granted' => $request->boolean('granted'),
            'at' => now(),
        ]);

        activity('privacy')
            ->performedOn($guest)
            ->causedBy($user)
            ->withProperties([
                'channel' => $request->string('channel')->value(),
                'purpose' => $request->string('purpose')->value(),
                'granted' => $request->boolean('granted'),
            ])
            ->log('Consent recorded.');

        return $this->flashSuccess('Consent recorded.');
    }

    /**
     * Campaign send list: opted-in guests only. Latest decision per
     * guest wins; anything else is a skip, never a silent drop.
     *
     * @return list<int>
     */
    public static function audience(string $channel, string $purpose): array
    {
        $ids = Consent::where('channel', $channel)
            ->where('purpose', $purpose)
            ->orderByDesc('at')
            ->orderByDesc('id')
            ->pluck('guest_id')
            ->unique();

        $audience = [];
        foreach ($ids as $id) {
            $guestId = is_numeric($id) ? (int) $id : 0;

            if ($guestId > 0 && Consent::granted($guestId, $channel, $purpose)) {
                $audience[] = $guestId;
            }
        }

        return $audience;
    }
}
