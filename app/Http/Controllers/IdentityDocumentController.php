<?php

namespace App\Http\Controllers;

use App\Exceptions\AvailabilityException;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Services\IdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class IdentityDocumentController extends Controller
{
    public function store(Request $request, Guest $guest): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $request->validate([
            'doc_type' => 'required|string|in:nin,passport,drivers',
            'doc_number' => 'nullable|string|max:128',
            'scan' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
        ]);

        $scan = $request->file('scan');
        $scan = $scan instanceof UploadedFile ? $scan : null;

        try {
            (new IdentityService)->capture(
                $guest,
                $request->string('doc_type')->value(),
                $request->string('doc_number')->value() ?: null,
                $scan,
                $user,
            );
        } catch (AvailabilityException $e) {
            return back()->withErrors(['scan' => $e->getMessage()]);
        }

        return $this->flashSuccess('Identity captured; OCR queued where a scan exists.');
    }

    public function download(Request $request, GuestIdentityDocument $document): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        try {
            $url = (new IdentityService)->scanUrl($document, $user);
        } catch (AvailabilityException $e) {
            abort(403, $e->getMessage());
        }

        return redirect()->away($url);
    }

    public function reveal(Request $request, GuestIdentityDocument $document): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        try {
            $number = (new IdentityService)->revealNumber($document, $user);
        } catch (AvailabilityException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json([
            'masked' => IdentityService::mask($number),
            'number' => $number,
        ]);
    }
}
