<?php

use App\Models\Branch;
use App\Models\PaymentConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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
