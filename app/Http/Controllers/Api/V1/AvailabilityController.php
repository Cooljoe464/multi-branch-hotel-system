<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\RoomType;
use App\Services\AvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var Branch $branch */
        $branch = $request->attributes->get('branch');

        $request->validate([
            'room_type_id' => 'required|integer',
            'check_in' => 'required|date_format:Y-m-d',
            'check_out' => 'required|date_format:Y-m-d|after:check_in',
            'adults' => 'nullable|integer|min:1|max:10',
        ]);

        $roomType = RoomType::findOrFail($request->integer('room_type_id'));
        abort_unless($roomType->branch_id === $branch->id, 404);

        $checkIn = $request->string('check_in')->value();
        $checkOut = $request->string('check_out')->value();

        $quote = (new AvailabilityService)->quote($branch, $roomType, $checkIn, $checkOut);

        return response()->json([
            'data' => [
                'branch_id' => $branch->id,
                'room_type_id' => $roomType->id,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'currency_code' => $branch->currency_code,
                'nights' => $quote,
            ],
        ]);
    }
}
