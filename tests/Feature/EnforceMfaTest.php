<?php

use App\Http\Middleware\EnforceMfa;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->branch = Branch::factory()->create();
});

it('allows users without sensitive permissions through', function () {
    $user = User::factory()->create(['branch_id' => $this->branch->id]);
    $request = Request::create('/test-mfa', 'GET');
    $request->setUserResolver(fn () => $user);

    $middleware = new EnforceMfa;
    $response = $middleware->handle($request, fn () => new Response('OK'));

    expect($response->getContent())->toBe('OK');
});

it('blocks users with sensitive permissions when MFA is not configured', function () {
    Permission::findOrCreate('security.audit');
    $user = User::factory()->create(['branch_id' => $this->branch->id]);
    $user->givePermissionTo('security.audit');

    $request = Request::create('/test-mfa', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $request->setUserResolver(fn () => $user);

    $middleware = new EnforceMfa;
    $response = $middleware->handle($request, fn () => new Response('OK'));

    expect($response->getStatusCode())->toBe(403);
    expect(json_decode($response->getContent(), true)['message'])->toBe('MFA_REQUIRED');
});

it('redirects web requests to security settings when MFA is not configured', function () {
    Permission::findOrCreate('security.audit');
    $user = User::factory()->create(['branch_id' => $this->branch->id]);
    $user->givePermissionTo('security.audit');

    $request = Request::create('/test-mfa', 'GET');
    $request->setUserResolver(fn () => $user);

    $middleware = new EnforceMfa;
    $response = $middleware->handle($request, fn () => new Response('OK'));

    expect($response->isRedirect(route('security.edit')))->toBeTrue();
});

it('allows users with sensitive permissions when two-factor is enabled', function () {
    Permission::findOrCreate('security.audit');
    $user = User::factory()->create([
        'branch_id' => $this->branch->id,
        'two_factor_secret' => 'secret',
        'two_factor_confirmed_at' => now(),
    ]);
    $user->givePermissionTo('security.audit');

    $request = Request::create('/test-mfa', 'GET');
    $request->setUserResolver(fn () => $user);

    $middleware = new EnforceMfa;
    $response = $middleware->handle($request, fn () => new Response('OK'));

    expect($response->getContent())->toBe('OK');
});
