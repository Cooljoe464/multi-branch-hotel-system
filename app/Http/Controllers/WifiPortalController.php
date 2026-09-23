<?php

namespace App\Http\Controllers;

use App\Services\WifiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WifiPortalController extends Controller
{
    public function validate(Request $request, WifiService $wifi): JsonResponse
    {
        $request->validate(['voucher' => 'required|string|max:32']);

        $session = $wifi->validate($request->string('voucher')->value());

        return response()->json([
            'data' => [
                'voucher' => $session->voucher,
                'expires_at' => $session->expires_at?->toIso8601String(),
            ],
        ]);
    }
}
