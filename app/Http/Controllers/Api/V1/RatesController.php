<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Services\RateEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RatesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Branch $branch */
        $branch = $request->attributes->get('branch');

        $request->validate([
            'room_type_id' => 'nullable|integer',
            'check_in' => 'nullable|date_format:Y-m-d',
            'check_out' => 'nullable|date_format:Y-m-d|after:check_in',
        ]);

        $checkIn = $request->string('check_in')->value();
        $checkOut = $request->string('check_out')->value();

        $quoteRoomType = null;
        $roomTypeId = $request->integer('room_type_id');
        if ($roomTypeId > 0 && $checkIn !== '' && $checkOut !== '') {
            $quoteRoomType = RoomType::query()->whereKey($roomTypeId)->firstOrFail();
            abort_unless($quoteRoomType->branch_id === $branch->id, 404);
        }

        $data = RatePlan::forBranch($branch->id)->active()->orderBy('id')->get()
            ->map(function (RatePlan $plan) use ($branch, $quoteRoomType, $checkIn, $checkOut) {
                $row = [
                    'id' => $plan->id,
                    'code' => $plan->code,
                    'name' => $plan->name,
                    'currency_code' => $branch->currency_code,
                ];

                if ($quoteRoomType !== null) {
                    $quote = (new RateEngine)->price($branch, $plan, $quoteRoomType, $checkIn, $checkOut);
                    $row['quote'] = [
                        'total_minor' => $quote['total_minor'],
                        'nights' => $quote['nights'],
                    ];
                }

                return $row;
            })->all();

        return response()->json(['data' => $data]);
    }
}
