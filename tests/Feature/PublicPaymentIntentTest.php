<?php

use App\Models\Branch;
use App\Models\PaymentConnection;
use App\Models\PaymentTransaction;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\PublicApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('AC-043 creates a hosted public checkout using the property payment connection', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.example/pay',
                'access_code' => 'access-code',
            ],
        ]),
    ]);

    $branch = Branch::factory()->create();
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
    Room::factory()->create(['branch_id' => $branch->id, 'room_type_id' => $roomType->id, 'status' => 'available']);
    RatePlan::factory()->forBranch($branch->id)->bar()->create([
        'code' => 'PUB'.Str::upper(Str::random(8)),
        'rate_multiplier' => 1,
        'valid_from' => now()->subYear()->toDateString(),
        'valid_to' => null,
    ]);
    $connection = PaymentConnection::factory()->forBranch($branch)->create([
        'provider' => 'paystack',
        'mode' => 'test',
        'secret_key' => 'sk_test_property_connection',
        'is_default' => true,
    ]);
    ['secret' => $secret] = app(PublicApiKeyService::class)->issue($branch, [
        'type' => 'secret',
        'environment' => 'test',
        'scopes' => ['availability.view', 'holds.create', 'holds.release', 'payments.create', 'reservations.view'],
    ]);
    $checkIn = now()->addDays(5)->toDateString();
    $checkOut = now()->addDays(7)->toDateString();

    $quote = $this->withHeaders(['X-API-Key' => $secret, 'X-Idempotency-Key' => 'public-payment-quote'])
        ->postJson('/public/v1/quotes', [
            'room_type_id' => $roomType->id,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
        ])
        ->assertOk();

    $hold = $this->withHeaders(['X-API-Key' => $secret, 'X-Idempotency-Key' => 'public-payment-hold'])
        ->postJson('/public/v1/holds', [
            'quote_token' => $quote->json('data.quote_token'),
            'session_id' => 'checkout-session',
        ])
        ->assertCreated();

    $intent = $this->withHeaders(['X-API-Key' => $secret, 'X-Idempotency-Key' => 'public-payment-intent'])
        ->postJson('/public/v1/payments/intents', [
            'hold_id' => $hold->json('data.id'),
            'guest_name' => 'Test Guest',
            'guest_email' => 'guest@example.test',
            'adults' => 2,
            'children' => 0,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.checkout_url', 'https://checkout.example/pay')
        ->assertJsonPath('data.amount_minor', $quote->json('data.total_minor'));

    $payment = PaymentTransaction::query()->where('payment_connection_id', $connection->id)->firstOrFail();
    expect($payment->metadata['public_payment_intent_id'])->toBe($intent->json('data.id'))
        ->and($payment->metadata['payment_connection_id'])->toBe($connection->id)
        ->and($intent->json('data.booking_access_token'))->toBeString();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.paystack.co/transaction/initialize'
        && $request->hasHeader('Authorization', 'Bearer sk_test_property_connection'));
});
