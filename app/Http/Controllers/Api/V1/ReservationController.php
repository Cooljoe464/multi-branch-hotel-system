<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReservationResource;
use App\Models\Branch;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\AvailabilityService;
use App\Services\GuaranteeService;
use App\Services\RateEngine;
use App\Services\WebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    public function store(Request $request, WebhookDispatcher $webhooks): JsonResponse
    {
        /** @var Branch $branch */
        $branch = $request->attributes->get('branch');

        $request->validate([
            'room_type_id' => 'required|integer',
            'check_in' => 'required|date_format:Y-m-d',
            'check_out' => 'required|date_format:Y-m-d|after:check_in',
            'guest_name' => 'required|string|max:128',
            'guest_email' => 'nullable|email|max:128',
            'guest_phone' => 'nullable|string|max:32',
            'adults' => 'required|integer|min:1|max:10',
            'children' => 'nullable|integer|min:0|max:10',
            'rate_plan_id' => 'nullable|integer',
            'source' => 'nullable|string|max:32',
        ]);

        $roomType = RoomType::findOrFail($request->integer('room_type_id'));
        abort_unless($roomType->branch_id === $branch->id, 404);

        $checkIn = $request->string('check_in')->value();
        $checkOut = $request->string('check_out')->value();

        $plan = null;
        $planId = $request->integer('rate_plan_id');
        if ($planId > 0) {
            $plan = RatePlan::findOrFail($planId);
            abort_unless($plan->branch_id === $branch->id, 404);
        } else {
            $plan = (new AvailabilityService)->defaultPlan($branch);
        }

        $quote = $plan !== null
            ? (new RateEngine)->price($branch, $plan, $roomType, $checkIn, $checkOut)
            : null;

        $key = $request->header('X-Idempotency-Key');
        $guestEmail = $request->string('guest_email')->value();
        $guestPhone = $request->string('guest_phone')->value();
        $source = $request->string('source')->value();

        $reservation = (new AvailabilityService)->reserve(
            branch: $branch,
            roomType: $roomType,
            checkIn: $checkIn,
            checkOut: $checkOut,
            attributes: [
                'currency_code' => $branch->currency_code,
                'guest_name' => $request->string('guest_name')->value(),
                'guest_email' => $guestEmail !== '' ? $guestEmail : null,
                'guest_phone' => $guestPhone !== '' ? $guestPhone : null,
                'adults' => $request->integer('adults'),
                'children' => $request->integer('children'),
                'room_rate' => $quote !== null ? ($quote['nights'][0]['total_minor'] ?? $roomType->base_rate) : $roomType->base_rate,
                'total_amount' => $quote !== null ? $quote['total_minor'] : $roomType->base_rate,
                'status' => 'confirmed',
                'source' => $source !== '' ? $source : 'direct',
                'payment_status' => 'pending',
            ],
            idempotencyKey: is_string($key) && trim($key) !== '' ? trim($key) : null,
            ratePlan: $plan,
            rateQuote: $quote,
        );

        $webhooks->dispatch('reservation.created', [
            'reservation_id' => $reservation->id,
            'confirmation_number' => $reservation->confirmation_number,
        ], $branch->id);

        return (new ReservationResource($reservation->fresh() ?? $reservation))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, int $id): ReservationResource
    {
        /** @var Branch $branch */
        $branch = $request->attributes->get('branch');

        $reservation = Reservation::findOrFail($id);
        abort_unless($reservation->branch_id === $branch->id, 404);

        return new ReservationResource($reservation);
    }

    public function cancel(Request $request, int $id, WebhookDispatcher $webhooks): JsonResponse
    {
        /** @var Branch $branch */
        $branch = $request->attributes->get('branch');

        $request->validate([
            'waive_penalty' => 'nullable|boolean',
        ]);

        $reservation = Reservation::findOrFail($id);
        abort_unless($reservation->branch_id === $branch->id, 404);

        $outcome = (new GuaranteeService)->cancelReservation($reservation, null, $request->boolean('waive_penalty'));

        $webhooks->dispatch('reservation.cancelled', [
            'reservation_id' => $reservation->id,
            'confirmation_number' => $reservation->confirmation_number,
            'penalty_minor' => $outcome['fee_minor'],
        ], $branch->id);

        return response()->json([
            'data' => new ReservationResource($reservation->fresh() ?? $reservation),
            'penalty_minor' => $outcome['fee_minor'],
        ]);
    }
}
