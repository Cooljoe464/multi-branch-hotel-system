<?php

use App\Events\WebhookRejected;
use App\Models\Branch;
use App\Models\Folio;
use App\Models\PaymentTransaction;
use App\Models\Reservation;
use App\Services\Payments\FlutterwaveDriver;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.flutterwave.secret_key' => 'flw-secret',
        'services.flutterwave.webhook_hash' => 'flw-hash',
        'services.paystack.secret_key' => 'ps-secret',
    ]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function gatewayTransaction(Branch $branch, string $driver, string $reference, array $attributes = []): PaymentTransaction
{
    $reservation = Reservation::factory()->checkedIn()->create(['branch_id' => $branch->id]);
    $folio = Folio::create([
        'branch_id' => $branch->id,
        'reservation_id' => $reservation->id,
        'folio_number' => 'FOL-'.$reference,
        'type' => 'individual',
        'status' => 'open',
        'balance' => 0,
    ]);

    return PaymentTransaction::create(array_merge([
        'branch_id' => $branch->id,
        'folio_id' => $folio->id,
        'reservation_id' => $reservation->id,
        'paystack_reference' => $reference,
        'type' => 'charge',
        'driver' => $driver,
        'status' => 'pending',
        'amount' => 50000,
        'currency' => 'NGN',
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $data
 */
function flutterwaveWebhook(array $data, ?string $hash = 'flw-hash'): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json'];

    if ($hash !== null) {
        $server['HTTP_VERIF_HASH'] = $hash;
    }

    return test()->call('POST', '/api/webhooks/flutterwave', [], [], [], $server, json_encode([
        'event' => 'charge.completed',
        'data' => $data,
    ]));
}

describe('driver', function () {
    test('initializes a hosted payment converting minor to major units', function () {
        Http::fake(['api.flutterwave.com/v3/payments' => Http::response(['data' => ['link' => 'https://checkout.flutterwave.test/abc']])]);

        $result = (new FlutterwaveDriver)->initialize(150050, 'NGN', 'guest@example.test', ['folio_id' => 7], 'https://app.test/return');

        expect($result['authorization_url'])->toBe('https://checkout.flutterwave.test/abc')
            ->and($result['reference'])->toStartWith('HMS-');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer flw-secret')
            && $request['amount'] === 1500.5
            && $request['currency'] === 'NGN'
            && $request['tx_ref'] === $result['reference']
            && $request['customer']['email'] === 'guest@example.test');
    });

    test('throws when initialization is rejected', function () {
        Http::fake(['api.flutterwave.com/*' => Http::response(['message' => 'bad key'], 401)]);

        expect(fn () => (new FlutterwaveDriver)->initialize(1000, 'NGN', 'g@example.test', []))
            ->toThrow(RuntimeException::class, 'Flutterwave initialization failed');
    });

    test('captures a pre-authorization in major units', function () {
        Http::fake(['api.flutterwave.com/v3/charges/FLW-1/capture' => Http::response(['data' => ['tx_ref' => 'HMS-CAP']])]);

        $result = (new FlutterwaveDriver)->capture('FLW-1', 25000);

        expect($result)->toBe(['reference' => 'HMS-CAP', 'captured_minor' => 25000]);
        Http::assertSent(fn ($request) => $request['amount'] === 250.0);
    });

    test('refunds against the transaction id found by reference', function () {
        Http::fake([
            'api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response(['data' => ['id' => 9911, 'status' => 'successful']]),
            'api.flutterwave.com/v3/transactions/9911/refund' => Http::response(['data' => ['id' => 55]]),
        ]);

        $result = (new FlutterwaveDriver)->refund('HMS-PAID', 10000);

        expect($result)->toBe(['reference' => 'REF-55', 'refunded_minor' => 10000]);
    });

    test('is selectable per branch through settings', function () {
        $branch = Branch::factory()->create(['settings' => ['payment_driver' => 'flutterwave']]);

        expect((new PaymentService)->forBranch($branch)->driver()->name())->toBe('flutterwave');
    });
});

describe('webhook', function () {
    test('returns 400 when the hash header is missing', function () {
        flutterwaveWebhook(['tx_ref' => 'X', 'status' => 'successful'], hash: null)
            ->assertStatus(400)
            ->assertJson(['message' => 'Missing signature.']);
    });

    test('returns 401 when the hash is wrong', function () {
        Event::fake([WebhookRejected::class]);

        flutterwaveWebhook(['tx_ref' => 'X', 'status' => 'successful'], hash: 'wrong')->assertUnauthorized();

        Event::assertDispatched(WebhookRejected::class, fn ($event) => $event->reason === 'invalid_signature');
    });

    test('settles the transaction and posts the payment to the folio', function () {
        $branch = Branch::factory()->create();
        $transaction = gatewayTransaction($branch, 'flutterwave', 'HMS-FLW-1');

        flutterwaveWebhook(['id' => 7001, 'tx_ref' => 'HMS-FLW-1', 'status' => 'successful', 'amount' => 500, 'currency' => 'NGN'])
            ->assertOk()
            ->assertJson(['message' => 'Webhook processed.']);

        expect($transaction->fresh()->status)->toBe('success')
            ->and($transaction->fresh()->paid_at)->not->toBeNull();
    });

    test('treats a redelivery as a replay and posts once', function () {
        $branch = Branch::factory()->create();
        $transaction = gatewayTransaction($branch, 'flutterwave', 'HMS-FLW-2');
        $data = ['id' => 7002, 'tx_ref' => 'HMS-FLW-2', 'status' => 'successful', 'amount' => 500, 'currency' => 'NGN'];

        flutterwaveWebhook($data)->assertOk();
        flutterwaveWebhook($data)->assertOk()->assertJson(['replayed' => true]);

        expect($transaction->folio->transactions()->count())->toBe(1);
    });

    test('marks the transaction failed when Flutterwave reports failure', function () {
        $branch = Branch::factory()->create();
        $transaction = gatewayTransaction($branch, 'flutterwave', 'HMS-FLW-3');

        flutterwaveWebhook(['id' => 7003, 'tx_ref' => 'HMS-FLW-3', 'status' => 'failed'])->assertOk();

        expect($transaction->fresh()->status)->toBe('failed');
    });

    test('rejects a short payment and leaves the transaction pending', function () {
        Event::fake([WebhookRejected::class]);
        $branch = Branch::factory()->create();
        $transaction = gatewayTransaction($branch, 'flutterwave', 'HMS-FLW-4');

        flutterwaveWebhook(['id' => 7004, 'tx_ref' => 'HMS-FLW-4', 'status' => 'successful', 'amount' => 100, 'currency' => 'NGN'])
            ->assertStatus(422);

        expect($transaction->fresh()->status)->toBe('pending');
        Event::assertDispatched(WebhookRejected::class, fn ($event) => $event->reason === 'amount_mismatch');
    });

    test('rejects a payment in the wrong currency', function () {
        $branch = Branch::factory()->create();
        $transaction = gatewayTransaction($branch, 'flutterwave', 'HMS-FLW-5');

        flutterwaveWebhook(['id' => 7005, 'tx_ref' => 'HMS-FLW-5', 'status' => 'successful', 'amount' => 500, 'currency' => 'USD'])
            ->assertStatus(422);

        expect($transaction->fresh()->status)->toBe('pending');
    });
});

describe('connection ownership (AC-043)', function () {
    test('a validly signed Flutterwave webhook cannot settle a Paystack transaction', function () {
        Event::fake([WebhookRejected::class]);
        $branch = Branch::factory()->create();
        $transaction = gatewayTransaction($branch, 'paystack', 'HMS-PS-1');

        flutterwaveWebhook(['id' => 8001, 'tx_ref' => 'HMS-PS-1', 'status' => 'successful', 'amount' => 500, 'currency' => 'NGN'])
            ->assertForbidden();

        expect($transaction->fresh()->status)->toBe('pending');
        Event::assertDispatched(
            WebhookRejected::class,
            fn ($event) => $event->reason === 'connection_mismatch' && $event->branchId === $branch->id,
        );
    });

    test('a validly signed Paystack webhook cannot settle a Flutterwave transaction', function () {
        Event::fake([WebhookRejected::class]);
        $branch = Branch::factory()->create();
        $transaction = gatewayTransaction($branch, 'flutterwave', 'HMS-FLW-9');
        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'HMS-FLW-9', 'amount' => 50000]]);

        $this->call('POST', '/api/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'ps-secret'),
        ], $body)->assertForbidden();

        expect($transaction->fresh()->status)->toBe('pending');
        Event::assertDispatched(WebhookRejected::class, fn ($event) => $event->reason === 'connection_mismatch');
    });

    test('a Paystack transaction recorded before the driver column still settles through Paystack', function () {
        $branch = Branch::factory()->create();
        $transaction = gatewayTransaction($branch, 'paystack', 'HMS-PS-2');
        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'HMS-PS-2', 'amount' => 50000]]);

        $this->call('POST', '/api/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'ps-secret'),
        ], $body)->assertOk();

        expect($transaction->fresh()->status)->toBe('success');
    });
});
