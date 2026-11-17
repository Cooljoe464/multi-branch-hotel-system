<?php

use App\Models\Branch;
use App\Models\DoorLockGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->branch = Branch::factory()->create();
    $this->user = $this->makeAdminUser($this->branch);
});

it('shows an empty state before a gateway exists', function () {
    $this->actingAs($this->user)
        ->get('/settings/door-lock')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/DoorLockGateway')
            ->where('gateway', null)
            ->has('providers'));
});

it('creates a gateway, keeps credentials out of the page and in the secret store', function () {
    $this->actingAs($this->user)->put('/settings/door-lock', [
        'provider' => 'ttlock',
        'api_base_url' => 'https://api.ttlock.example',
        'api_key' => 'key-123',
        'api_secret' => 'secret-456',
        'lock_id' => 'L-9',
        'manual_mode' => true,
    ])->assertRedirect();

    $gateway = DoorLockGateway::forBranch($this->branch->id)->sole();

    expect($gateway->apiKey())->toBe('key-123')
        ->and($gateway->apiSecret())->toBe('secret-456')
        ->and($gateway->api_key)->toBe('')
        ->and($gateway->settings['manual_mode'])->toBeTrue();

    $this->actingAs($this->user)
        ->get('/settings/door-lock')
        ->assertInertia(fn ($page) => $page
            ->where('gateway.has_api_key', true)
            ->missing('gateway.api_key'))
        ->assertDontSee('key-123');
});

it('keeps the stored key when the form is saved without a new one', function () {
    $payload = ['provider' => 'ttlock', 'api_base_url' => 'https://api.ttlock.example', 'api_key' => 'key-123'];

    $this->actingAs($this->user)->put('/settings/door-lock', $payload);
    $this->actingAs($this->user)->put('/settings/door-lock', ['provider' => 'ttlock', 'api_base_url' => 'https://api.ttlock.example']);

    expect(DoorLockGateway::forBranch($this->branch->id)->sole()->apiKey())->toBe('key-123');
});

it('rejects an unknown provider and a non-https URL', function () {
    $this->actingAs($this->user)->put('/settings/door-lock', [
        'provider' => 'nope',
        'api_base_url' => 'http://insecure.example',
    ])->assertSessionHasErrors(['provider', 'api_base_url']);
});

it('removes the gateway and lets it be configured again', function () {
    $payload = ['provider' => 'ttlock', 'api_base_url' => 'https://api.ttlock.example', 'api_key' => 'k'];
    $this->actingAs($this->user)->put('/settings/door-lock', $payload);
    $this->actingAs($this->user)->delete('/settings/door-lock')->assertRedirect();

    expect(DoorLockGateway::forBranch($this->branch->id)->count())->toBe(0);

    $this->actingAs($this->user)->put('/settings/door-lock', $payload)->assertRedirect();

    expect(DoorLockGateway::forBranch($this->branch->id)->count())->toBe(1);
});
