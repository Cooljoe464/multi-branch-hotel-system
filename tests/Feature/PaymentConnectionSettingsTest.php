<?php

use App\Models\ApiConsumer;
use App\Models\Branch;
use App\Models\PaymentConnection;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->branch = Branch::factory()->create();
    $this->user = $this->makeAdminUser($this->branch);
});

it('lists connections without exposing secrets', function () {
    PaymentConnection::factory()->create([
        'tenant_id' => $this->branch->tenant_id,
        'branch_id' => $this->branch->id,
        'secret_key' => 'sk_live_super_secret',
    ]);

    $this->actingAs($this->user)
        ->get('/settings/payment-connections')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/PaymentConnections')
            ->has('connections', 1)
            ->missing('connections.0.secret_key'))
        ->assertDontSee('sk_live_super_secret');
});

it('stores a connection with encrypted secrets and keeps one default per scope', function () {
    $payload = [
        'name' => 'Main account',
        'provider' => 'paystack',
        'mode' => 'test',
        'scope' => 'this_property',
        'secret_key' => 'sk_test_abc',
        'webhook_secret' => 'whsec_abc',
        'is_default' => true,
    ];

    $this->actingAs($this->user)->post('/settings/payment-connections', $payload)->assertRedirect();
    $this->actingAs($this->user)->post('/settings/payment-connections', [...$payload, 'name' => 'Second'])->assertRedirect();

    $connections = PaymentConnection::where('tenant_id', $this->branch->tenant_id)->get();

    expect($connections)->toHaveCount(2)
        ->and($connections->where('is_default', true))->toHaveCount(1)
        ->and($connections->firstWhere('name', 'Second')->is_default)->toBeTrue()
        ->and($connections->first()->secret_key)->toBe('sk_test_abc')
        ->and(DB::table('payment_connections')->where('id', $connections->first()->id)->value('secret_key'))->not->toBe('sk_test_abc');
});

it('rejects an unsupported provider', function () {
    $this->actingAs($this->user)->post('/settings/payment-connections', [
        'name' => 'Bad',
        'provider' => 'stripe',
        'mode' => 'test',
        'scope' => 'this_property',
        'secret_key' => 'x',
    ])->assertSessionHasErrors('provider');
});

it('does not let one tenant change another tenant connection', function () {
    $other = PaymentConnection::factory()->create();

    $this->actingAs($this->user)
        ->put("/settings/payment-connections/{$other->id}", ['status' => 'disabled'])
        ->assertForbidden();

    expect($other->fresh()->status)->toBe('active');
});

it('runs a live test connection diagnostic against the stored gateway credentials', function () {
    $connection = PaymentConnection::factory()->create([
        'tenant_id' => $this->branch->tenant_id,
        'branch_id' => $this->branch->id,
        'provider' => 'paystack',
        'mode' => 'test',
        'secret_key' => 'sk_test_abc',
        'status' => 'active',
    ]);

    Http::fake([
        'api.paystack.co/*' => Http::response([
            'status' => true,
            'data' => ['merchant_name' => 'Herd Hotels Ltd'],
        ], 200),
    ]);

    $this->actingAs($this->user)
        ->post("/settings/payment-connections/{$connection->id}/test")
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.mode', 'test')
        ->assertJsonPath('data.merchant_name', 'Herd Hotels Ltd')
        ->assertJsonPath('data.reachable', true);
});

it('fails the live test connection diagnostic when the gateway rejects the credentials', function () {
    $connection = PaymentConnection::factory()->create([
        'tenant_id' => $this->branch->tenant_id,
        'branch_id' => $this->branch->id,
        'provider' => 'flutterwave',
        'mode' => 'live',
        'secret_key' => 'FLW_LIVE_abc',
        'status' => 'active',
    ]);

    Http::fake([
        'api.flutterwave.com/*' => Http::response(['message' => 'Unauthorized'], 401),
    ]);

    $this->actingAs($this->user)
        ->post("/settings/payment-connections/{$connection->id}/test")
        ->assertStatus(422)
        ->assertJsonPath('data.status', 'invalid')
        ->assertJsonPath('data.mode', 'live')
        ->assertJsonPath('data.reachable', false);
});

it('does not run the live test connection diagnostic against another tenant connection', function () {
    $other = PaymentConnection::factory()->create();

    $this->actingAs($this->user)
        ->post("/settings/payment-connections/{$other->id}/test")
        ->assertForbidden();
});

it('dispatches a signed payment test webhook delivery to the branch consumer', function () {
    Http::fake();

    $connection = PaymentConnection::factory()->create([
        'tenant_id' => $this->branch->tenant_id,
        'branch_id' => $this->branch->id,
        'provider' => 'paystack',
        'mode' => 'test',
        'status' => 'active',
    ]);

    $consumer = ApiConsumer::create([
        'name' => 'Booking Engine',
        'branch_id' => $this->branch->id,
        'scopes' => ['reservations.create'],
        'webhook_url' => 'https://partner.example/hooks/pms',
        'webhook_secret' => str_repeat('a', 64),
        'is_active' => true,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("/settings/payment-connections/{$connection->id}/webhook-tests")
        ->assertOk();

    $delivery = WebhookDelivery::query()->where('api_consumer_id', $consumer->id)->first();

    expect($delivery)->not->toBeNull()
        ->and($delivery->event)->toBe('payment.test')
        ->and($delivery->signature)->toStartWith('sha256=')
        ->and($delivery->status)->toBe(WebhookDelivery::STATUS_DELIVERED)
        ->and($response->json('data.status'))->toBe(WebhookDelivery::STATUS_DELIVERED)
        ->and($response->json('data.delivery_id'))->toBe($delivery->id);
});

it('reports a failed payment test webhook when no consumer is registered for the branch', function () {
    $connection = PaymentConnection::factory()->create([
        'tenant_id' => $this->branch->tenant_id,
        'branch_id' => $this->branch->id,
        'provider' => 'paystack',
        'mode' => 'test',
        'status' => 'active',
    ]);

    $this->actingAs($this->user)
        ->postJson("/settings/payment-connections/{$connection->id}/webhook-tests")
        ->assertStatus(422)
        ->assertJsonPath('data.status', 'failed');
});

it('does not send a test webhook against another tenant connection', function () {
    $other = PaymentConnection::factory()->create();

    $this->actingAs($this->user)
        ->postJson("/settings/payment-connections/{$other->id}/webhook-tests")
        ->assertForbidden();
});

it('surfaces the latest webhook delivery on the payment connection list', function () {
    PaymentConnection::factory()->create([
        'tenant_id' => $this->branch->tenant_id,
        'branch_id' => $this->branch->id,
        'provider' => 'paystack',
        'mode' => 'test',
        'status' => 'active',
    ]);

    $consumer = ApiConsumer::create([
        'name' => 'Booking Engine',
        'branch_id' => $this->branch->id,
        'scopes' => ['reservations.create'],
        'webhook_url' => 'https://partner.example/hooks/pms',
        'webhook_secret' => str_repeat('a', 64),
        'is_active' => true,
    ]);

    WebhookDelivery::create([
        'api_consumer_id' => $consumer->id,
        'event' => 'payment.test',
        'payload' => ['event' => 'payment.test'],
        'signature' => 'sha256='.str_repeat('a', 64),
        'status' => WebhookDelivery::STATUS_DELIVERED,
        'attempts' => 1,
    ]);

    $this->actingAs($this->user)
        ->get('/settings/payment-connections')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/PaymentConnections')
            ->has('connections', 1)
            ->has('connections.0.last_webhook')
            ->where('connections.0.last_webhook.status', WebhookDelivery::STATUS_DELIVERED));
});

it('deletes a connection that has no payments', function () {
    $connection = PaymentConnection::factory()->create([
        'tenant_id' => $this->branch->tenant_id,
        'branch_id' => $this->branch->id,
    ]);

    $this->actingAs($this->user)
        ->delete("/settings/payment-connections/{$connection->id}")
        ->assertRedirect();

    expect(PaymentConnection::find($connection->id))->toBeNull();
});
