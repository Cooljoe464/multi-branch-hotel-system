<?php

use App\Models\Branch;
use App\Models\InventoryHold;
use App\Models\PaymentConnection;
use App\Models\PublicApiQuote;
use App\Models\PublicPaymentIntent;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\FolioService;
use App\Services\PublicApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('cancels only a token-authorized public booking and applies the hotel policy', function () {
    $branch = Branch::factory()->create();
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    $ratePlan = RatePlan::factory()->forBranch($branch->id)->bar()->create([
        'code' => 'CAN'.Str::upper(Str::random(8)),
        'rate_multiplier' => 1,
        'valid_from' => now()->subYear()->toDateString(),
        'valid_to' => null,
    ]);
    ['key' => $apiKey, 'secret' => $secret] = app(PublicApiKeyService::class)->issue($branch, [
        'type' => 'secret',
        'scopes' => ['reservations.cancel'],
    ]);
    $quote = PublicApiQuote::factory()->forBooking($apiKey, $roomType, $ratePlan)->create();
    $hold = InventoryHold::create([
        'tenant_id' => $branch->tenant_id,
        'branch_id' => $branch->id,
        'room_type_id' => $roomType->id,
        'api_key_id' => $apiKey->id,
        'public_api_quote_id' => $quote->id,
        'source' => 'public_api',
        'check_in' => now()->addDays(10)->toDateString(),
        'check_out' => now()->addDays(12)->toDateString(),
        'status' => 'active',
        'session_id' => 'cancel-session',
        'client_ip' => '192.0.2.1',
        'expires_at' => now()->addMinutes(10),
    ]);
    $connection = PaymentConnection::factory()->forBranch($branch)->create();
    $folio = app(FolioService::class)->createFolio($branch->id);
    $reservation = Reservation::factory()->create([
        'branch_id' => $branch->id,
        'room_type_id' => $roomType->id,
        'rate_plan_id' => $ratePlan->id,
        'source' => 'public_api',
        'status' => 'confirmed',
        'check_in_date' => now()->addDays(10)->toDateString(),
        'check_out_date' => now()->addDays(12)->toDateString(),
    ]);
    $bookingToken = Str::random(64);
    PublicPaymentIntent::create([
        'tenant_id' => $branch->tenant_id,
        'branch_id' => $branch->id,
        'api_key_id' => $apiKey->id,
        'hold_id' => $hold->id,
        'quote_id' => $quote->id,
        'payment_connection_id' => $connection->id,
        'folio_id' => $folio->id,
        'reservation_id' => $reservation->id,
        'guest_name' => $reservation->guest_name,
        'guest_email' => $reservation->guest_email ?? 'guest@example.test',
        'adults' => 1,
        'children' => 0,
        'status' => 'paid',
        'access_token_hash' => hash('sha256', $bookingToken),
        'amount_minor' => $reservation->total_amount,
        'currency_code' => $branch->currency_code,
        'expires_at' => now()->addMinutes(10),
    ]);

    $path = '/public/v1/reservations/'.$reservation->confirmation_number.'/cancel';

    $this->withHeaders([
        'X-API-Key' => $secret,
        'X-Booking-Token' => 'wrong-token',
        'X-Idempotency-Key' => 'unauthorized-cancel',
    ])->postJson($path)->assertNotFound();

    $this->withHeaders([
        'X-API-Key' => $secret,
        'X-Booking-Token' => $bookingToken,
        'X-Idempotency-Key' => 'authorized-cancel',
    ])->postJson($path)
        ->assertOk()
        ->assertJsonPath('data.confirmation_number', $reservation->confirmation_number)
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancellation_fee_minor', 0);

    expect($reservation->fresh()->status)->toBe('cancelled');
});
