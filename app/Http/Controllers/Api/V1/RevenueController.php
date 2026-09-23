<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\RevenueAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RevenueController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var Branch $branch */
        $branch = $request->attributes->get('branch');

        $validated = $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
        ]);

        return response()->json([
            'data' => (new RevenueAnalyticsService)->metrics($branch, $request->string('from')->value(), $request->string('to')->value()),
        ]);
    }
}
