<?php

use App\Contracts\LockProvider;
use App\Models\Branch;
use App\Models\DoorLockAuditLog;
use App\Models\DoorLockGateway;
use App\Models\Reservation;
use App\Models\Room;
use App\Services\DoorLock\AssaAbloyProvider;
use App\Services\DoorLock\DormakabaProvider;
use App\Services\DoorLock\DuowinProvider;
use App\Services\DoorLock\SaltoProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['is_active' => true]);
    $this->room = Room::factory()->forBranch($this->branch->id)->create(['status' => 'occupied']);
    $this->reservation = Reservation::factory()->forBranch($this->branch->id)->forGuest()->create([
        'room_id' => $this->room->id,
        'status' => 'checked_in',
        'check_in_date' => now()->subDay()->toDateString(),
        'check_out_date' => now()->addDays(3)->toDateString(),
    ]);
});

/**
 * Every provider the PMS ships with, so a new vendor only needs adding
 * here to inherit the shared contract tests.
 *
 * @return array<string, class-string<LockProvider>>
 */
function lockProviderMap(): array
{
    return [
        'assa_abloy' => AssaAbloyProvider::class,
        'salto' => SaltoProvider::class,
        'duowin' => DuowinProvider::class,
        'dormakaba' => DormakabaProvider::class,
    ];
}

/**
 * @return list<array{0: string, 1: class-string<LockProvider>}>
 */
function lockProviderDatasets(): array
{
    $datasets = [];

    foreach (lockProviderMap() as $provider => $class) {
        $datasets[] = [$provider, $class];
    }

    return $datasets;
}

function lockGatewayFor(string $provider, Branch $branch): DoorLockGateway
{
    $gateway = DoorLockGateway::factory()->forBranch($branch)->create([
        'provider' => $provider,
        'api_base_url' => "https://locks.{$provider}.test/v1",
    ]);

    if ($provider === 'dormakaba') {
        $gateway->update([
            'settings' => array_merge($gateway->settings ?? [], ['client_id' => 'dk-client']),
        ]);
    }

    return $gateway;
}

function lockLogFor(DoorLockGateway $gateway, Reservation $reservation, Room $room, array $overrides = []): DoorLockAuditLog
{
    return DoorLockAuditLog::factory()
        ->forReservation($reservation)
        ->forRoom($room)
        ->forGateway($gateway)
        ->create(array_merge([
            'branch_id' => $gateway->branch_id,
            'action' => 'issue_key',
            'status' => 'pending',
            'valid_from' => now(),
            'valid_until' => now()->addDays(2),
            'pin_code' => '482913',
        ], $overrides));
}

/**
 * Token endpoints answer with a bearer token, everything else with the
 * canned vendor status — one fake covers all four vendors.
 */
function fakeLockVendor(int $status = 200): void
{
    Http::fake(function ($request) use ($status) {
        if (str_contains($request->url(), '/oauth/token')) {
            return Http::response(['access_token' => 'vendor-token', 'expires_in' => 3600], 200);
        }

        if ($status !== 200) {
            return Http::response(['error' => 'vendor rejected'], $status);
        }

        return Http::response(['id' => 'CRED-1'], 200);
    });
}

it('exposes one provider name per implementation', function (string $provider, string $class) {
    expect((new $class)->getProviderName())->toBe($provider);
})->with(lockProviderDatasets());

it('issues a keycard for every provider against a live-shaped API', function (string $provider, string $class) {
    fakeLockVendor();

    $gateway = lockGatewayFor($provider, $this->branch);
    $log = lockLogFor($gateway, $this->reservation, $this->room);

    $result = (new $class)->issueKey($gateway, $log);

    $log->refresh();

    expect($result)->toBeTrue()
        ->and($log->status)->toBe('success')
        ->and($log->credential_id)->toBe('CRED-1')
        ->and($log->payload['request'] ?? null)->not->toBeNull();

    if ($provider === 'dormakaba') {
        // The access code stays out of the audit trail.
        expect($log->payload['request'])->not->toHaveKey('code');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/access_codes')
            && $request->hasHeader('Authorization', 'Bearer vendor-token'));
    } else {
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization'));
    }
})->with(lockProviderDatasets());

it('revokes and extends a stored credential for every provider', function (string $provider, string $class) {
    fakeLockVendor();

    $gateway = lockGatewayFor($provider, $this->branch);
    $providerInstance = new $class;

    $revoked = lockLogFor($gateway, $this->reservation, $this->room, [
        'action' => 'revoke_key',
        'status' => 'success',
        'credential_id' => 'CRED-1',
    ]);
    $extended = lockLogFor($gateway, $this->reservation, $this->room, [
        'action' => 'extend_key',
        'status' => 'success',
        'credential_id' => 'CRED-1',
    ]);

    expect($providerInstance->revokeKey($gateway, $revoked))->toBeTrue()
        ->and($revoked->refresh()->status)->toBe('revoked')
        ->and($providerInstance->extendKey($gateway, $extended))->toBeTrue()
        ->and($extended->refresh()->payload['extended'] ?? null)->toBeTrue();
})->with(lockProviderDatasets());

it('marks the log failed when the vendor rejects the call', function (string $provider, string $class) {
    fakeLockVendor(500);

    $gateway = lockGatewayFor($provider, $this->branch);
    $log = lockLogFor($gateway, $this->reservation, $this->room);

    $result = (new $class)->issueKey($gateway, $log);

    $log->refresh();

    expect($result)->toBeFalse()
        ->and($log->status)->toBe('failed')
        ->and($log->payload['error'] ?? null)->not->toBeNull();
})->with(lockProviderDatasets());

it('refuses revoke and extend without a credential id', function (string $provider, string $class) {
    fakeLockVendor();

    $gateway = lockGatewayFor($provider, $this->branch);
    $providerInstance = new $class;

    $revokeLog = lockLogFor($gateway, $this->reservation, $this->room, [
        'action' => 'revoke_key',
        'status' => 'success',
        'credential_id' => null,
    ]);
    $extendLog = lockLogFor($gateway, $this->reservation, $this->room, [
        'action' => 'extend_key',
        'status' => 'success',
        'credential_id' => null,
    ]);

    expect($providerInstance->revokeKey($gateway, $revokeLog))->toBeFalse()
        ->and($providerInstance->extendKey($gateway, $extendLog))->toBeFalse();
})->with(lockProviderDatasets());

it('signs vendor calls with the secret-store credential, not the column', function () {
    fakeLockVendor();

    $gateway = lockGatewayFor('salto', $this->branch);
    $gateway->storeApiKey('rotated-secret-key');

    expect($gateway->fresh()->api_key)->toBe('')
        ->and($gateway->apiKey())->toBe('rotated-secret-key');

    $log = lockLogFor($gateway, $this->reservation, $this->room);

    expect((new SaltoProvider)->issueKey($gateway, $log))->toBeTrue();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer rotated-secret-key'));
});

it('fails cleanly when no credentials are configured', function (string $provider, string $class) {
    fakeLockVendor();

    $gateway = lockGatewayFor($provider, $this->branch);
    $gateway->forceFill(['api_key' => '', 'api_secret' => null])->save();

    if ($provider === 'dormakaba') {
        $gateway->update([
            'settings' => array_diff_key($gateway->settings ?? [], ['client_id' => true]),
        ]);
    }

    $log = lockLogFor($gateway, $this->reservation, $this->room);

    expect((new $class)->issueKey($gateway, $log))->toBeFalse()
        ->and($log->refresh()->status)->toBe('failed')
        ->and($log->payload['error'] ?? null)->toContain('configured');
})->with(lockProviderDatasets());
