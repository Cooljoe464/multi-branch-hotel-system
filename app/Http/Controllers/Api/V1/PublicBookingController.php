<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Branch;
use App\Models\PublicApiQuote;
use App\Models\PublicPaymentIntent;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\AvailabilityService;
use App\Services\GuaranteeService;
use App\Services\PublicPaymentIntentService;
use App\Services\RateEngine;
use App\Services\TaxService;
use App\Services\WebhookDispatcher;
use App\Support\BranchTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class PublicBookingController extends Controller
{
    public function property(Request $request, int $propertyId): JsonResponse
    {
        $branch = $this->branch($request);
        abort_unless($branch->id === $propertyId, 404);

        return response()->json(['data' => [
            'id' => $branch->id,
            'name' => $branch->name,
            'address' => $branch->address,
            'city' => $branch->city,
            'state' => $branch->state,
            'country' => $branch->country,
            'phone' => $branch->phone,
            'email' => $branch->email,
            'timezone' => $branch->timezone,
            'currency_code' => $branch->currency_code,
            'locale' => $branch->locale,
        ]]);
    }

    public function roomTypes(Request $request): JsonResponse
    {
        $branch = $this->branch($request);
        $roomTypes = RoomType::query()->forBranch($branch->id)->active()->orderBy('id')->get()
            ->map(fn (RoomType $roomType): array => [
                'id' => $roomType->id,
                'name' => $roomType->name,
                'code' => $roomType->code,
                'description' => $roomType->description,
                'max_occupancy' => $roomType->max_occupancy,
                'bed_count' => $roomType->bed_count,
                'bed_type' => $roomType->bed_type,
                'amenities' => $roomType->amenities ?? [],
                'base_rate_minor' => $roomType->base_rate,
                'currency_code' => $branch->currency_code,
            ])->all();

        return response()->json(['data' => $roomTypes]);
    }

    public function availability(Request $request, AvailabilityService $availability): JsonResponse
    {
        $branch = $this->branch($request);
        $request->validate([
            'room_type_id' => ['required', 'integer'],
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
        ]);

        $checkIn = $request->string('check_in')->value();
        $checkOut = $request->string('check_out')->value();
        abort_if($checkIn < BranchTime::today($branch), 422, 'Check-in must be today or a future date at this property.');

        $roomType = $this->roomType($branch, $request->integer('room_type_id'));

        return response()->json(['data' => [
            'room_type_id' => $roomType->id,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'currency_code' => $branch->currency_code,
            ...$availability->quote($branch, $roomType, $checkIn, $checkOut, 'public_api'),
        ]]);
    }

    public function quote(Request $request, AvailabilityService $availability, RateEngine $rates, TaxService $taxes): JsonResponse
    {
        $branch = $this->branch($request);
        $request->validate([
            'room_type_id' => ['required', 'integer'],
            'rate_plan_id' => ['nullable', 'integer'],
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
        ]);

        $checkIn = $request->string('check_in')->value();
        $checkOut = $request->string('check_out')->value();
        abort_if($checkIn < BranchTime::today($branch), 422, 'Check-in must be today or a future date at this property.');

        $roomType = $this->roomType($branch, $request->integer('room_type_id'));
        abort_unless(
            $availability->quote($branch, $roomType, $checkIn, $checkOut, 'public_api')['available'],
            409,
            'The room type is not available for the requested stay.',
        );
        $ratePlanId = $request->integer('rate_plan_id');
        $lastNight = Carbon::parse($checkOut)->subDay()->toDateString();
        $ratePlanQuery = RatePlan::query()
            ->forBranch($branch->id)
            ->active()
            ->whereDate('valid_from', '<=', $checkIn)
            ->where(function ($query) use ($lastNight): void {
                $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $lastNight);
            });

        if ($ratePlanId > 0) {
            $ratePlanQuery->whereKey($ratePlanId);
        } else {
            $ratePlanQuery->orderByRaw("CASE WHEN type = 'bar' THEN 0 ELSE 1 END")->orderBy('id');
        }

        $ratePlan = $ratePlanQuery->first();
        abort_if($ratePlan === null, 422, 'No active rate plan covers the requested stay dates.');

        $rateQuote = $rates->price($branch, $ratePlan, $roomType, $checkIn, $checkOut);
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('public_api_key');
        $quoteToken = Str::random(64);
        $expiresAt = now()->addMinutes(15);
        $taxLines = [];
        $totalTaxMinor = 0;
        $totalMinor = 0;

        foreach ($rateQuote['nights'] as $night) {
            $profile = $taxes->profileForDate($branch->id, $night['date']);
            $tax = $profile === null
                ? ['gross_minor' => $night['total_minor'], 'total_minor' => 0, 'lines' => [], 'snapshot' => []]
                : $taxes->compute($night['total_minor'], $profile, 'room_rate', effectiveDate: $night['date']);

            $totalMinor += $tax['gross_minor'];
            $totalTaxMinor += $tax['total_minor'];
            $taxLines[] = [
                'date' => $night['date'],
                'profile_id' => $profile?->id,
                'snapshot' => $tax['snapshot'],
                'base_minor' => $night['total_minor'],
                'gross_minor' => $tax['gross_minor'],
                'tax_minor' => $tax['total_minor'],
                'lines' => $tax['lines'],
            ];
        }

        $quote = PublicApiQuote::create([
            'tenant_id' => $branch->tenant_id,
            'branch_id' => $branch->id,
            'api_key_id' => $apiKey->id,
            'room_type_id' => $roomType->id,
            'rate_plan_id' => $ratePlan->id,
            'quote_hash' => hash('sha256', $quoteToken),
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'currency_code' => $branch->currency_code,
            'subtotal_minor' => $rateQuote['total_minor'],
            'tax_total_minor' => $totalTaxMinor,
            'total_minor' => $totalMinor,
            'rate_snapshot' => $rateQuote,
            'tax_snapshot' => $taxLines,
            'expires_at' => $expiresAt,
        ]);

        return response()->json(['data' => [
            'quote_id' => $quote->id,
            'quote_token' => $quoteToken,
            'property_id' => $branch->id,
            'room_type_id' => $roomType->id,
            'rate_plan_id' => $ratePlan->id,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'currency_code' => $branch->currency_code,
            'rate_subtotal_minor' => $rateQuote['total_minor'],
            'tax_total_minor' => $totalTaxMinor,
            'total_minor' => $totalMinor,
            'nights' => $rateQuote['nights'],
            'tax_breakdown' => $taxLines,
            'expires_at' => $expiresAt->toIso8601String(),
        ]]);
    }

    public function paymentIntent(Request $request, PublicPaymentIntentService $intents): JsonResponse
    {
        $branch = $this->branch($request);
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('public_api_key');
        $request->validate([
            'hold_id' => ['required', 'integer', 'min:1'],
            'guest_name' => ['required', 'string', 'max:128'],
            'guest_email' => ['required', 'email', 'max:128'],
            'guest_phone' => ['nullable', 'string', 'max:32'],
            'adults' => ['required', 'integer', 'min:1', 'max:10'],
            'children' => ['nullable', 'integer', 'min:0', 'max:10'],
        ]);

        $phone = $request->input('guest_phone');
        $created = $intents->create($apiKey, $branch, $request->integer('hold_id'), [
            'name' => $request->string('guest_name')->value(),
            'email' => $request->string('guest_email')->value(),
            'phone' => is_string($phone) ? $phone : null,
            'adults' => $request->integer('adults'),
            'children' => $request->integer('children'),
        ]);
        /** @var PublicPaymentIntent $intent */
        $intent = $created['intent'];

        return response()->json(['data' => [
            'id' => $intent->id,
            'status' => $intent->status,
            'checkout_url' => $intent->checkout_url,
            'amount_minor' => $intent->amount_minor,
            'currency_code' => $intent->currency_code,
            'expires_at' => $intent->expires_at->toIso8601String(),
            'booking_access_token' => $created['access_token'],
        ]], 201);
    }

    public function store(Request $request): JsonResponse
    {
        $branch = $this->branch($request);
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('public_api_key');
        $request->validate([
            'payment_intent_id' => ['required', 'integer', 'min:1'],
        ]);

        $intent = PublicPaymentIntent::query()
            ->where('branch_id', $branch->id)
            ->whereKey($request->integer('payment_intent_id'))
            ->firstOrFail();

        if ($intent->status !== 'paid') {
            if ($intent->reservation_id !== null) {
                abort(409, 'The public payment intent has already been confirmed.');
            }

            abort(422, 'The public payment intent must be verified before a reservation can be created.');
        }

        $reservation = $intent->reservation_id === null
            ? app(PublicPaymentIntentService::class)->confirm($intent)
            : Reservation::query()->whereKey($intent->reservation_id)->firstOrFail();

        return response()->json(['data' => $this->reservationPayload($reservation)]);
    }

    public function reservation(Request $request, string $confirmationNumber): JsonResponse
    {
        $branch = $this->branch($request);
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('public_api_key');
        $reservation = Reservation::query()
            ->where('branch_id', $branch->id)
            ->where('source', 'public_api')
            ->where('confirmation_number', $confirmationNumber)
            ->firstOrFail();

        $this->authorizeBookingToken($request, $apiKey, $reservation);

        return response()->json(['data' => $this->reservationPayload($reservation)]);
    }

    public function cancelReservation(
        Request $request,
        string $confirmationNumber,
        GuaranteeService $guarantees,
        WebhookDispatcher $webhooks,
    ): JsonResponse {
        $branch = $this->branch($request);
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('public_api_key');
        $reservation = Reservation::query()
            ->where('branch_id', $branch->id)
            ->where('source', 'public_api')
            ->where('confirmation_number', $confirmationNumber)
            ->firstOrFail();

        $this->authorizeBookingToken($request, $apiKey, $reservation);

        $wasCancelled = $reservation->status === 'cancelled';
        $outcome = $wasCancelled
            ? ['fee_minor' => 0, 'waived' => false]
            : $guarantees->cancelReservation($reservation, null);
        $reservation->refresh();

        if (! $wasCancelled) {
            $webhooks->dispatch('reservation.cancelled', [
                'reservation_id' => $reservation->id,
                'confirmation_number' => $reservation->confirmation_number,
                'cancellation_fee_minor' => $outcome['fee_minor'],
                'source' => 'public_api',
            ], $branch->id);
        }

        return response()->json(['data' => [
            'confirmation_number' => $reservation->confirmation_number,
            'status' => $reservation->status,
            'cancellation_fee_minor' => $outcome['fee_minor'],
            'currency_code' => $reservation->currency_code,
        ]]);
    }

    private function branch(Request $request): Branch
    {
        $branch = $request->attributes->get('branch');
        abort_unless($branch instanceof Branch, 404);

        return $branch;
    }

    private function roomType(Branch $branch, int $roomTypeId): RoomType
    {
        return RoomType::query()
            ->forBranch($branch->id)
            ->active()
            ->whereKey($roomTypeId)
            ->firstOrFail();
    }

    private function reservationPayload(Reservation $reservation): array
    {
        return [
            'confirmation_number' => $reservation->confirmation_number,
            'status' => $reservation->status,
            'payment_status' => $reservation->payment_status,
            'guest_name' => $reservation->guest_name,
            'guest_email' => $reservation->guest_email,
            'guest_phone' => $reservation->guest_phone,
            'room_type_id' => $reservation->room_type_id,
            'check_in' => $reservation->check_in_date->toDateString(),
            'check_out' => $reservation->check_out_date->toDateString(),
            'total_minor' => $reservation->total_amount,
            'currency_code' => $reservation->currency_code,
        ];
    }

    private function authorizeBookingToken(Request $request, ApiKey $apiKey, Reservation $reservation): void
    {
        $token = $request->header('X-Booking-Token');
        abort_unless(is_string($token) && $token !== '', 401, 'A booking access token is required.');

        $intent = PublicPaymentIntent::query()
            ->where('api_key_id', $apiKey->id)
            ->where('reservation_id', $reservation->id)
            ->first();
        abort_unless($intent instanceof PublicPaymentIntent && hash_equals($intent->access_token_hash, hash('sha256', $token)), 404);
    }
}
