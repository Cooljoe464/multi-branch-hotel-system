<?php

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['timezone' => 'Africa/Lagos']);
    $this->seed(RoleSeeder::class);
});

it('redirects the authenticated admin to the configured identity provider authorize endpoint', function () {
    config([
        'sso.providers' => [
            'google' => [
                'issuer' => 'https://accounts.google.com',
                'client_id' => 'google-client-id',
                'client_secret' => 'google-client-secret',
                'tenant' => null,
            ],
        ],
    ]);

    Http::fake([
        'https://accounts.google.com/*' => Http::response([
            'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_endpoint' => 'https://oauth2.googleapis.com/token',
            'userinfo_endpoint' => 'https://openidconnect.googleapis.com/v1/userinfo',
        ]),
    ]);

    $admin = User::factory()->create(['branch_id' => $this->branch->id, 'is_global_admin' => true]);
    $admin->assignRole('Global Admin');
    $admin->branches()->syncWithoutDetaching([$this->branch->id]);

    $response = $this->actingAs($admin)->get('/sso/google/authorize');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('https://accounts.google.com/o/oauth2/v2/auth');
});

it('authenticates a user from the identity provider callback and provisions the account', function () {
    config([
        'sso.providers' => [
            'google' => [
                'issuer' => 'https://accounts.google.com',
                'client_id' => 'google-client-id',
                'client_secret' => 'google-client-secret',
                'tenant' => null,
            ],
        ],
    ]);

    Http::fake([
        'https://accounts.google.com/*' => Http::response([
            'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_endpoint' => 'https://oauth2.googleapis.com/token',
            'userinfo_endpoint' => 'https://openidconnect.googleapis.com/v1/userinfo',
        ]),
        'https://oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.sso-token']),
        'https://openidconnect.googleapis.com/*' => Http::response([
            'sub' => 'google-sub-123',
            'email' => 'sso.user@example.com',
            'name' => 'SSO User',
        ]),
    ]);

    $admin = User::factory()->create(['branch_id' => $this->branch->id, 'is_global_admin' => true]);
    $admin->assignRole('Global Admin');
    $admin->branches()->syncWithoutDetaching([$this->branch->id]);

    $response = $this->actingAs($admin)
        ->withSession(['sso_state' => 'state-123', 'sso_nonce' => 'nonce-123'])
        ->get('/sso/google/callback?state=state-123&nonce=nonce-123&code=auth-code-123');

    $response->assertRedirect('/dashboard');

    $user = User::query()
        ->where('sso_issuer', 'https://accounts.google.com')
        ->where('sso_subject', 'google-sub-123')
        ->first();

    expect($user)->not->toBeNull()
        ->and($user?->email)->toBe('sso.user@example.com');

    $this->assertAuthenticated();
});

it('rejects the identity provider callback when the state check fails', function () {
    config([
        'sso.providers' => [
            'google' => [
                'issuer' => 'https://accounts.google.com',
                'client_id' => 'google-client-id',
                'client_secret' => 'google-client-secret',
                'tenant' => null,
            ],
        ],
    ]);

    $admin = User::factory()->create(['branch_id' => $this->branch->id, 'is_global_admin' => true]);
    $admin->assignRole('Global Admin');
    $admin->branches()->syncWithoutDetaching([$this->branch->id]);

    $response = $this->actingAs($admin)
        ->withSession(['sso_state' => 'state-123', 'sso_nonce' => 'nonce-123'])
        ->get('/sso/google/callback?state=evil-state&nonce=nonce-123&code=auth-code-123');

    $response->assertForbidden();
});
