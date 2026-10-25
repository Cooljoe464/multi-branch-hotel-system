<?php

use App\Models\AccountingLink;
use App\Models\Branch;
use App\Services\Accounting\AccountingDriverManager;
use App\Services\Accounting\AccountingOAuthManager;
use App\Services\Security\SecretManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->user = $this->makeAdminUser($this->branch);

    config([
        'services.xero.client_id' => 'env-client-id',
        'services.xero.client_secret' => 'env-client-secret',
    ]);
});

/**
 * @return array{state: string, code: string}
 */
function oauthCallbackParams(TestResponse $response, string $code = 'auth-code-1'): array
{
    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return ['state' => (string) ($query['state'] ?? ''), 'code' => $code];
}

it('redirects to the vendor consent screen with a one-time state', function () {
    $response = $this->actingAs($this->user)
        ->get(route('accounting.connect', [$this->branch, 'xero']));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('login.xero.com/identity/connect/authorize');

    $params = oauthCallbackParams($response);

    expect($params['state'])->not->toBe('')
        ->and($response->headers->get('Location'))
        ->toContain('client_id=env-client-id')
        ->toContain('accounting%2Foauth%2Fcallback');

    $link = AccountingLink::sole();

    expect($link->branch_id)->toBe($this->branch->id)
        ->and($link->provider)->toBe('xero')
        ->and($link->is_active)->toBeTrue();
});

it('exchanges the callback code and stores provider-scoped tokens', function () {
    Http::fake([
        'identity.xero.com/oauth2/token' => Http::response([
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'token_type' => 'Bearer',
            'expires_in' => 1800,
        ], 200),
        'api.xero.com/connections' => Http::response([
            ['tenantId' => 'tenant-42'],
        ], 200),
    ]);

    $connect = $this->actingAs($this->user)
        ->get(route('accounting.connect', [$this->branch, 'xero']));
    $params = oauthCallbackParams($connect);

    $this->actingAs($this->user)
        ->get(route('accounting.oauth.callback', [
            'state' => $params['state'],
            'code' => $params['code'],
        ]))
        ->assertRedirect(route('accounting.index', $this->branch));

    $link = AccountingLink::sole();
    $tokens = $link->readTokens();

    expect($tokens['access_token'] ?? null)->toBe('access-1')
        ->and($tokens['refresh_token'] ?? null)->toBe('refresh-1')
        ->and($tokens['tenant_id'] ?? null)->toBe('tenant-42')
        ->and($tokens['expires_at'] ?? null)->not->toBeNull();

    Http::assertSent(fn ($request) => $request->url() === 'https://identity.xero.com/oauth2/token'
        && $request['grant_type'] === 'authorization_code'
        && $request['client_id'] === 'env-client-id'
        && $request['code'] === $params['code']);
});

it('rejects a foreign or replayed state without exchanging anything', function () {
    Http::fake([
        'identity.xero.com/oauth2/token' => Http::response([
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'expires_in' => 1800,
        ], 200),
        'api.xero.com/connections' => Http::response([['tenantId' => 'tenant-42']], 200),
    ]);

    $connect = $this->actingAs($this->user)
        ->get(route('accounting.connect', [$this->branch, 'xero']));
    $params = oauthCallbackParams($connect);

    // Garbage state never reaches the vendor.
    $this->actingAs($this->user)
        ->get(route('accounting.oauth.callback', ['state' => 'not-a-state', 'code' => 'x']))
        ->assertRedirect(route('dashboard'));

    // A state minted for another branch's link is refused outright.
    $otherBranch = Branch::factory()->create();
    $otherLink = AccountingLink::create([
        'branch_id' => $otherBranch->id,
        'provider' => 'xero',
        'sandbox' => true,
        'is_active' => true,
    ]);
    $foreignState = app(AccountingOAuthManager::class)->authorizeUrl($otherLink);
    parse_str((string) parse_url($foreignState, PHP_URL_QUERY), $foreignQuery);

    $this->actingAs($this->user)
        ->get(route('accounting.oauth.callback', ['state' => $foreignQuery['state'], 'code' => 'y']))
        ->assertForbidden();

    // First valid exchange works, the replay finds a consumed nonce.
    $this->actingAs($this->user)
        ->get(route('accounting.oauth.callback', ['state' => $params['state'], 'code' => $params['code']]))
        ->assertRedirect(route('accounting.index', $this->branch));

    $this->actingAs($this->user)
        ->get(route('accounting.oauth.callback', ['state' => $params['state'], 'code' => $params['code']]))
        ->assertRedirect(route('dashboard'));

    Http::assertSentCount(2); // one code exchange (+ tenant lookup); replays and forgeries send nothing

    $ownLink = AccountingLink::where('branch_id', $this->branch->id)->sole();
    expect($ownLink->readTokens()['access_token'] ?? null)->toBe('access-1');
});

it('refreshes an expiring access token before the export call', function () {
    Http::fake([
        'identity.xero.com/oauth2/token' => Http::response([
            'access_token' => 'access-fresh',
            'refresh_token' => 'refresh-fresh',
            'expires_in' => 1800,
        ], 200),
        'api.xero.com/*' => Http::response([
            'ManualJournals' => [['ManualJournalID' => 'MJ-9']],
        ], 200),
    ]);

    $link = AccountingLink::create([
        'branch_id' => $this->branch->id,
        'provider' => 'xero',
        'sandbox' => true,
        'is_active' => true,
    ]);
    $link->storeTokens([
        'access_token' => 'access-stale',
        'refresh_token' => 'refresh-stale',
        'tenant_id' => 'tenant-42',
        'expires_at' => now()->subMinute()->toIso8601String(),
    ]);

    $id = app(AccountingDriverManager::class)->driver('xero')->export($link, now()->toDateString(), [
        'debits' => 5000,
        'credits' => 5000,
        'lines' => [[
            'chart_code' => 'CASH',
            'external_account' => 'EXT-CASH',
            'debit_minor' => 5000,
            'credit_minor' => 5000,
        ]],
    ]);

    expect($id)->toBe('MJ-9')
        ->and($link->readTokens()['access_token'] ?? null)->toBe('access-fresh');

    Http::assertSent(fn ($request) => $request->url() === 'https://identity.xero.com/oauth2/token'
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'refresh-stale');
    Http::assertSent(fn ($request) => $request->url() === 'https://api.xero.com/api.xro/2.0/ManualJournals'
        && $request->hasHeader('Authorization', 'Bearer access-fresh'));
});

it('prefers branch-scoped client credentials over the environment', function () {
    $secrets = app(SecretManager::class);
    $secrets->set($this->branch, 'xero.client_id', 'branch-client-id');

    $link = AccountingLink::create([
        'branch_id' => $this->branch->id,
        'provider' => 'xero',
        'sandbox' => true,
        'is_active' => true,
    ]);

    $credentials = app(AccountingOAuthManager::class)->clientCredentials($link->branch, 'xero');

    expect($credentials['client_id'])->toBe('branch-client-id')
        ->and($credentials['client_secret'])->toBe('env-client-secret');
});
