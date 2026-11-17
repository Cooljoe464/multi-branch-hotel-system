<?php

use App\Models\Branch;
use App\Models\Folio;
use App\Models\PaymentConnection;
use App\Models\PaymentTransaction;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('initializes hosted checkout using the branch default payment connection', function () {
    $branch = Branch::factory()->create();
    $connection = PaymentConnection::factory()->forBranch($branch)->create([
        'provider' => 'paystack',
        'secret_key' => 'branch-gateway-secret',
        'merchant_reference' => 'merchant-a',
    ]);
    $folio = Folio::factory()->create(['branch_id' => $branch->id]);
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.example/session',
                'access_code' => 'access-code',
            ],
        ]),
    ]);

    $payment = (new PaymentService)->forBranch($branch)->initializePayment($folio, 12500, 'guest@example.test');

    Http::assertSent(fn (HttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer branch-gateway-secret')
        && $request['metadata']['merchant_reference'] === 'merchant-a');

    expect(PaymentTransaction::query()->where('paystack_reference', $payment['reference'])->value('payment_connection_id'))
        ->toBe($connection->id);
});

it('AC-043 rejects a valid connection A signature with a transaction reference from connection B', function () {
    $branchA = Branch::factory()->create();
    $branchB = Branch::factory()->create();
    $connectionA = PaymentConnection::factory()->forBranch($branchA)->create([
        'provider' => 'paystack',
        'webhook_secret' => 'connection-a-webhook-secret',
    ]);
    $connectionB = PaymentConnection::factory()->forBranch($branchB)->create([
        'provider' => 'paystack',
        'webhook_secret' => 'connection-b-webhook-secret',
    ]);
    $folioB = Folio::factory()->create(['branch_id' => $branchB->id]);
    $transactionB = PaymentTransaction::factory()->forFolio($folioB)->create([
        'payment_connection_id' => $connectionB->id,
        'paystack_reference' => 'CONNECTION-B-REFERENCE',
    ]);
    $payload = [
        'event' => 'charge.success',
        'data' => ['reference' => $transactionB->paystack_reference],
    ];
    $rawPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $signature = hash_hmac('sha512', $rawPayload, 'connection-a-webhook-secret');

    $this->withHeader('x-paystack-signature', $signature)
        ->postJson('/webhooks/paystack/'.$connectionA->id, $payload)
        ->assertForbidden();

    expect($transactionB->fresh()->status)->toBe('pending');
});

it('verifies and settles a hosted payment through its owning connection', function () {
    $branch = Branch::factory()->create();
    $connection = PaymentConnection::factory()->forBranch($branch)->create([
        'provider' => 'paystack',
        'secret_key' => 'paystack-secret-key',
        'webhook_secret' => 'connection-webhook-secret',
    ]);
    $folio = Folio::factory()->create(['branch_id' => $branch->id]);
    $transaction = PaymentTransaction::factory()->forFolio($folio)->create([
        'payment_connection_id' => $connection->id,
        'driver' => 'paystack',
        'amount' => 12500,
        'currency' => 'NGN',
        'paystack_reference' => 'CONNECTION-A-REFERENCE',
    ]);
    Http::fake([
        'api.paystack.co/transaction/verify/CONNECTION-A-REFERENCE' => Http::response([
            'status' => true,
            'data' => [
                'reference' => $transaction->paystack_reference,
                'status' => 'success',
                'amount' => 12500,
                'currency' => 'NGN',
            ],
        ]),
    ]);
    $payload = [
        'event' => 'charge.success',
        'data' => ['reference' => $transaction->paystack_reference],
    ];
    $rawPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $signature = hash_hmac('sha512', $rawPayload, 'connection-webhook-secret');

    $this->withHeader('x-paystack-signature', $signature)
        ->postJson('/webhooks/paystack/'.$connection->id, $payload)
        ->assertOk()
        ->assertJsonPath('replayed', false);

    expect($transaction->fresh()->status)->toBe('success');
});
