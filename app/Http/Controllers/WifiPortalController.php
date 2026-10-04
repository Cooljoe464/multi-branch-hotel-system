<?php

namespace App\Http\Controllers;

use App\Services\WifiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WifiPortalController extends Controller
{
    public function validate(Request $request, WifiService $wifi): JsonResponse
    {
        $request->validate([
            'voucher' => 'required|string|max:32',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'mac' => 'nullable|string|max:32',
            'nas_ip' => 'nullable|string|max:64',
        ]);

        $session = $wifi->validate($request->string('voucher')->value());

        if ($request->filled('branch_id') && (int) $session->branch_id !== $request->integer('branch_id')) {
            abort(404);
        }

        if ($request->filled('mac') || $request->filled('nas_ip')) {
            Log::info('Hotspot portal auth attempt', [
                'branch' => $session->branch_id,
                'mac' => $request->string('mac')->value(),
                'nas_ip' => $request->string('nas_ip')->value(),
            ]);
        }

        $session->loadMissing('tier');

        return response()->json([
            'data' => [
                'voucher' => $session->voucher,
                'username' => $session->username ?? $session->voucher,
                'expires_at' => $session->expires_at?->toIso8601String(),
                'tier' => $session->tier ? [
                    'code' => $session->tier->code,
                    'name' => $session->tier->name,
                    'rate_up_kbps' => $session->tier->rate_up_kbps,
                    'rate_down_kbps' => $session->tier->rate_down_kbps,
                    'device_limit' => $session->tier->device_limit,
                ] : null,
            ],
        ]);
    }
}
