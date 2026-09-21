<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\RoomType;
use App\Services\AvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    use EnsuresBranchAccess;

    public function quote(Request $request, Branch $branch, AvailabilityService $availability): JsonResponse
    {
        $this->ensureBranchAccess($branch);

        $validated = $request->validate([
            'room_type_id' => 'required|exists:room_types,id',
            'check_in' => 'required|date',
            'check_out' => 'required|date|after:check_in',
        ]);

        $roomType = RoomType::findOrFail($request->integer('room_type_id'));

        if ($roomType->branch_id !== $branch->id) {
            abort(403, 'The room type does not belong to this property.');
        }

        return response()->json(
            $availability->quote(
                $branch,
                $roomType,
                $request->string('check_in')->value(),
                $request->string('check_out')->value(),
            )
        );
    }
}
