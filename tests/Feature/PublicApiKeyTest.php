<?php

use App\Models\ApiKeyAuditLog;
use App\Models\Branch;
use App\Models\InventoryHold;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PublicApiKeyService;
use App\Support\TenantDatabaseContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(RefreshDatabase::class);

function publicApiQuoteToken(TestCase $test, Branch $branch, RoomType $roomType, string $secret, string $checkIn, string $checkOut): string
{
    RatePlan::factory()->forBranch($branch->id)->bar()->create([
        'code' => 'Q'.Str::upper(Str::random(8)),
        'rate_multiplier' => 1,
        'valid_from' => now()->subYear()->toDateString(),
        'valid_to' => null,
    ]);

    $response = $test->withHeader('X-API-Key', $secret)
        ->withHeader('X-Idempotency-Key', 'quote-'.Str::uuid())
        ->postJson('/public/v1/quotes', [
            'room_type_id' => $roomType->id,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
        ]);

    $response->assertOk();

    $quoteToken = $response->json('data.quote_token');
    if (! is_string($quoteToken)) {
        throw new RuntimeException('The public quote did not return its opaque token.');
    }

    return $quoteToken;
}

/**
 * @param  array{check_in: string, check_out: string, session_id: string}  $payload
 * @param  array<string, string>  $headers
 */
function postPublicApiHold(TestCase $test, Branch $branch, RoomType $roomType, string $secret, array $payload, string $idempotencyKey, array $headers = []): TestResponse
{
    $quoteToken = publicApiQuoteToken(
        $test,
        $branch,
        $roomType,
        $secret,
        $payload['check_in'],
        $payload['check_out'],
    );
    $request = $test->withHeader('X-API-Key', $secret)
        ->withHeader('X-Idempotency-Key', $idempotencyKey);

    foreach ($headers as $name => $value) {
        $request->withHeader($name, $value);
    }

    return $request->postJson('/public/v1/holds', [
        'quote_token' => $quoteToken,
        'session_id' => $payload['session_id'],
    ]);
}

it('AC-049 keeps the public API on its separate versioned surface', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes());

    expect($routes->first(fn ($route): bool => $route->getName() === 'public.v1.holds.store')?->uri())
        ->toBe('public/v1/holds')
        ->and($routes->first(fn ($route): bool => $route->getName() === 'api.v1.public.holds.store'))
        ->toBeNull();
});

it('serves public property, room type, availability, and tax-aware quote data within the key property', function () {
    $branch = Branch::factory()->create();
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
    Room::factory()->create(['branch_id' => $branch->id, 'room_type_id' => $roomType->id, 'status' => 'available']);
    RatePlan::factory()->forBranch($branch->id)->bar()->create([
        'rate_multiplier' => 1,
        'valid_from' => now()->subYear()->toDateString(),
        'valid_to' => null,
    ]);
    ['secret' => $secret] = app(PublicApiKeyService::class)->issue($branch, ['scopes' => ['availability.view']]);
    $headers = ['X-API-Key' => $secret];
    $checkIn = now()->addDays(5)->toDateString();
    $checkOut = now()->addDays(7)->toDateString();

    $this->withHeaders($headers)
        ->getJson('/public/v1/properties/'.$branch->id)
        ->assertOk()
        ->assertJsonPath('data.id', $branch->id);

    $this->withHeaders($headers)
        ->getJson('/public/v1/room-types')
        ->assertOk()
        ->assertJsonPath('data.0.id', $roomType->id);

    $this->withHeaders($headers)
        ->getJson('/public/v1/availability?'.http_build_query([
            'room_type_id' => $roomType->id,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
        ]))
        ->assertOk()
        ->assertJsonPath('data.available', true);

    $this->withHeaders($headers)
        ->withHeader('X-Idempotency-Key', 'public-quote-1')
        ->postJson('/public/v1/quotes', [
            'room_type_id' => $roomType->id,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
        ])
        ->assertOk()
        ->assertJsonPath('data.room_type_id', $roomType->id)
        ->assertJsonPath('data.tax_total_minor', 0)
        ->assertJsonPath('data.total_minor', $roomType->base_rate * 2);
});

it('requires a quote token for holds and consumes that token only once', function () {
    $branch = Branch::factory()->create();
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    Room::factory()->create(['branch_id' => $branch->id, 'room_type_id' => $roomType->id, 'status' => 'available']);
    ['secret' => $secret] = app(PublicApiKeyService::class)->issue($branch, [
        'scopes' => ['availability.view', 'holds.create'],
    ]);
    $checkIn = now()->addDays(5)->toDateString();
    $checkOut = now()->addDays(6)->toDateString();
    $quoteToken = publicApiQuoteToken($this, $branch, $roomType, $secret, $checkIn, $checkOut);
    $payload = ['quote_token' => $quoteToken, 'session_id' => 'quoted-session'];

    $this->withHeader('X-API-Key', $secret)
        ->withHeader('X-Idempotency-Key', 'quoted-hold-1')
        ->postJson('/public/v1/holds', $payload)
        ->assertCreated();

    $this->withHeader('X-API-Key', $secret)
        ->withHeader('X-Idempotency-Key', 'quoted-hold-2')
        ->postJson('/public/v1/holds', $payload)
        ->assertUnprocessable();
});

it('AC-048 rejects a public hold without a key', function () {
    $this->postJson('/public/v1/holds', [
        'room_type_id' => 1,
        'check_in' => now()->addDay()->toDateString(),
        'check_out' => now()->addDays(2)->toDateString(),
        'session_id' => 'session-1',
    ])->assertUnauthorized();
});

it('AC-048 rejects hold creation when the public key lacks the required scope', function () {
    $branch = Branch::factory()->create();
    ['secret' => $secret] = app(PublicApiKeyService::class)->issue($branch, [
        'scopes' => ['availability.view'],
    ]);

    $this->withHeader('X-API-Key', $secret)
        ->postJson('/public/v1/holds', [
            'room_type_id' => 1,
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(2)->toDateString(),
            'session_id' => 'read-only-session',
        ])
        ->assertForbidden();
});

it('AC-049 enforces the public key origin allowlist', function () {
    $branch = Branch::factory()->create();
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    Room::factory()->create(['branch_id' => $branch->id, 'room_type_id' => $roomType->id, 'status' => 'available']);
    ['secret' => $secret] = app(PublicApiKeyService::class)->issue($branch, [
        'allowed_origins' => ['https://allowed.example'],
    ]);

    $this->withHeader('X-API-Key', $secret)
        ->withHeader('Origin', 'https://blocked.example')
        ->postJson('/public/v1/holds', [
            'room_type_id' => $roomType->id,
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(2)->toDateString(),
            'session_id' => 'session-1',
        ])
        ->assertForbidden();
});

it('AC-049 denies browser origins when a key has no origin allowlist', function () {
    $branch = Branch::factory()->create();
    ['secret' => $secret] = app(PublicApiKeyService::class)->issue($branch, [
        'scopes' => ['availability.view', 'holds.create'],
    ]);

    $this->withHeader('X-API-Key', $secret)
        ->withHeader('Origin', 'https://unconfigured.example')
        ->postJson('/public/v1/holds', [])
        ->assertForbidden();
});

it('AC-049 returns CORS headers and accepts preflight for an allowed origin', function () {
    $branch = Branch::factory()->create();
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    Room::factory()->create(['branch_id' => $branch->id, 'room_type_id' => $roomType->id, 'status' => 'available']);
    ['secret' => $secret] = app(PublicApiKeyService::class)->issue($branch, [
        'allowed_origins' => ['https://allowed.example'],
    ]);

    postPublicApiHold($this, $branch, $roomType, $secret, [
        'check_in' => now()->addDay()->toDateString(),
        'check_out' => now()->addDays(2)->toDateString(),
        'session_id' => 'session-cors',
    ], 'cors-hold-1', ['Origin' => 'https://allowed.example'])
        ->assertCreated()
        ->assertHeader('Access-Control-Allow-Origin', 'https://allowed.example');

    $this->withHeader('Origin', 'https://allowed.example')
        ->withHeader('Access-Control-Request-Method', 'POST')
        ->withHeader('Access-Control-Request-Headers', 'content-type,x-api-key,x-idempotency-key')
        ->options('/public/v1/holds?publishable_key='.urlencode($secret))
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'https://allowed.example')
        ->assertHeader('Access-Control-Allow-Headers', 'Content-Type, X-API-Key, X-Idempotency-Key')
        ->assertHeader('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS');
});

it('AC-049 does not accept a secret key in a browser preflight URL', function () {
    $branch = Branch::factory()->create();
    ['secret' => $secret] = app(PublicApiKeyService::class)->issue($branch, [
        'type' => 'secret',
    ]);

    $this->withHeader('Origin', 'https://allowed.example')
        ->options('/public/v1/holds?publishable_key='.urlencode($secret))
        ->assertUnauthorized();
});

it('keeps secret API keys server-to-server only', function () {
    $branch = Branch::factory()->create();
    ['secret' => $secret] = app(PublicApiKeyService::class)->issue($branch, ['type' => 'secret']);

    $this->withHeader('X-API-Key', $secret)
        ->withHeader('Origin', 'https://browser.example')
        ->getJson('/public/v1/room-types')
        ->assertForbidden();
});

it('AC-048 creates and releases an inventory hold through the public API', function () {
    $branch = Branch::factory()->create();
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    Room::factory()->create(['branch_id' => $branch->id, 'room_type_id' => $roomType->id, 'status' => 'available']);
    ['secret' => $secret] = app(PublicApiKeyService::class)->issue($branch, [
        'hold_limit_per_session' => 1,
    ]);

    $response = postPublicApiHold($this, $branch, $roomType, $secret, [
        'check_in' => now()->addDay()->toDateString(),
        'check_out' => now()->addDays(2)->toDateString(),
        'session_id' => 'session-1',
    ], 'create-release-1');

    $response->assertCreated()->assertJsonPath('data.status', 'active');

    $holdId = $response->json('data.id');

    $this->withHeader('X-API-Key', $secret)
        ->withHeader('X-Idempotency-Key', 'release-1')
        ->deleteJson('/public/v1/holds/'.$holdId)
        ->assertOk()
        ->assertJsonPath('data.status', 'released');
});

it('replays the original public hold response and rejects key reuse with a changed payload', function () {
    $branch = Branch::factory()->create();
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    Room::factory()->count(2)->create(['branch_id' => $branch->id, 'room_type_id' => $roomType->id, 'status' => 'available']);
    ['secret' => $secret] = app(PublicApiKeyService::class)->issue($branch, [
        'scopes' => ['availability.view', 'holds.create'],
    ]);
    $quoteToken = publicApiQuoteToken(
        $this,
        $branch,
        $roomType,
        $secret,
        now()->addDay()->toDateString(),
        now()->addDays(2)->toDateString(),
    );
    $payload = ['quote_token' => $quoteToken, 'session_id' => 'retry-session'];
    $sendHold = fn (array $body) => $this->withHeader('X-API-Key', $secret)
        ->withHeader('X-Idempotency-Key', 'hold-retry-identity')
        ->postJson('/public/v1/holds', $body);

    $first = $sendHold($payload)->assertCreated();
    $replay = $sendHold($payload)
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true');

    expect($replay->json('data.id'))->toBe($first->json('data.id'))
        ->and(InventoryHold::query()->count())->toBe(1);

    $sendHold(['quote_token' => $quoteToken, 'session_id' => 'changed-session'])->assertUnprocessable();
});

it('AC-048 rejects a second active hold over the session cap', function () {
    $branch = Branch::factory()->create();
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    Room::factory()->count(2)->create(['branch_id' => $branch->id, 'room_type_id' => $roomType->id, 'status' => 'available']);
    ['secret' => $secret] = app(PublicApiKeyService::class)->issue($branch, [
        'hold_limit_per_session' => 1,
    ]);

    $payload = [
        'check_in' => now()->addDay()->toDateString(),
        'check_out' => now()->addDays(2)->toDateString(),
        'session_id' => 'session-1',
    ];

    postPublicApiHold($this, $branch, $roomType, $secret, $payload, 'session-cap-1')->assertCreated();
    postPublicApiHold($this, $branch, $roomType, $secret, $payload, 'session-cap-2')->assertStatus(422)->assertJsonPath('code', 'HOLD_CAP_EXCEEDED');
});

it('AC-048 enforces the IP cap across public API keys for the same property', function () {
    $branch = Branch::factory()->create();
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    Room::factory()->count(2)->create(['branch_id' => $branch->id, 'room_type_id' => $roomType->id, 'status' => 'available']);
    ['secret' => $firstKey] = app(PublicApiKeyService::class)->issue($branch, [
        'hold_limit_per_ip' => 1,
    ]);
    ['secret' => $secondKey] = app(PublicApiKeyService::class)->issue($branch, [
        'hold_limit_per_ip' => 1,
    ]);
    $basePayload = [
        'check_in' => now()->addDay()->toDateString(),
        'check_out' => now()->addDays(2)->toDateString(),
    ];

    postPublicApiHold($this, $branch, $roomType, $firstKey, $basePayload + ['session_id' => 'session-a'], 'ip-cap-1')
        ->assertCreated();

    postPublicApiHold($this, $branch, $roomType, $secondKey, $basePayload + ['session_id' => 'session-b'], 'ip-cap-2')
        ->assertStatus(422)
        ->assertJsonPath('code', 'HOLD_CAP_EXCEEDED');
});

it('AC-048 enforces the session cap across public API keys for the same property', function () {
    $branch = Branch::factory()->create();
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    Room::factory()->count(2)->create(['branch_id' => $branch->id, 'room_type_id' => $roomType->id, 'status' => 'available']);
    ['secret' => $firstKey] = app(PublicApiKeyService::class)->issue($branch, [
        'hold_limit_per_session' => 1,
    ]);
    ['secret' => $secondKey] = app(PublicApiKeyService::class)->issue($branch, [
        'hold_limit_per_session' => 1,
    ]);
    $payload = [
        'check_in' => now()->addDay()->toDateString(),
        'check_out' => now()->addDays(2)->toDateString(),
        'session_id' => 'shared-client-session',
    ];

    postPublicApiHold($this, $branch, $roomType, $firstKey, $payload, 'shared-session-1')
        ->assertCreated();

    postPublicApiHold($this, $branch, $roomType, $secondKey, $payload, 'shared-session-2')
        ->assertStatus(422)
        ->assertJsonPath('code', 'HOLD_CAP_EXCEEDED');
});

it('meters public API requests against the tenant daily-call entitlement', function () {
    $branch = Branch::factory()->create();
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    Room::factory()->count(2)->create(['branch_id' => $branch->id, 'room_type_id' => $roomType->id, 'status' => 'available']);
    $plan = Plan::factory()->create();
    PlanEntitlement::create([
        'plan_id' => $plan->id,
        'entitlement_key' => 'api.public',
        'value_type' => PlanEntitlement::TYPE_BOOLEAN,
        'value' => 'true',
    ]);
    PlanEntitlement::create([
        'plan_id' => $plan->id,
        'entitlement_key' => 'limits.api.daily_calls',
        'value_type' => PlanEntitlement::TYPE_INTEGER_LIMIT,
        'value' => '3',
    ]);
    Subscription::factory()->for($branch->tenant)->for($plan)->create();
    ['secret' => $secret, 'key' => $key] = app(PublicApiKeyService::class)->issue($branch, [
        'scopes' => ['availability.view', 'holds.create'],
    ]);
    $payload = [
        'check_in' => now()->addDay()->toDateString(),
        'check_out' => now()->addDays(2)->toDateString(),
        'session_id' => 'meter-session',
    ];

    postPublicApiHold($this, $branch, $roomType, $secret, $payload, 'meter-call-1')
        ->assertCreated();

    postPublicApiHold($this, $branch, $roomType, $secret, $payload + ['session_id' => 'meter-session-2'], 'meter-call-2')
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    $usageCount = app(TenantDatabaseContext::class)->run(
        null,
        $branch->tenant_id,
        fn (): int => (int) ($key->dailyUsage()->whereDate('usage_date', now()->toDateString())->value('request_count') ?? 0),
        actorType: 'test',
    );

    expect($usageCount)->toBe(3);
});

it('rate limits public API calls per key and returns Retry-After with CORS headers', function () {
    config(['public_api.requests_per_minute' => 1]);
    $branch = Branch::factory()->create();
    ['secret' => $secret] = app(PublicApiKeyService::class)->issue($branch, [
        'allowed_origins' => ['https://allowed.example'],
    ]);
    $url = '/public/v1/holds?publishable_key='.urlencode($secret);

    $this->withHeader('Origin', 'https://allowed.example')
        ->options($url)
        ->assertNoContent();

    $this->withHeader('Origin', 'https://allowed.example')
        ->options($url)
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertHeader('Access-Control-Allow-Origin', 'https://allowed.example');
});

it('scopes idempotency keys to the public API key', function () {
    $branch = Branch::factory()->create();
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    Room::factory()->count(2)->create(['branch_id' => $branch->id, 'room_type_id' => $roomType->id, 'status' => 'available']);
    ['secret' => $firstKey] = app(PublicApiKeyService::class)->issue($branch, []);
    ['secret' => $secondKey] = app(PublicApiKeyService::class)->issue($branch, []);
    $payload = [
        'check_in' => now()->addDay()->toDateString(),
        'check_out' => now()->addDays(2)->toDateString(),
        'session_id' => 'same-client-session',
    ];

    $first = postPublicApiHold($this, $branch, $roomType, $firstKey, $payload, 'shared-request-key')
        ->assertCreated();
    $second = postPublicApiHold($this, $branch, $roomType, $secondKey, $payload, 'shared-request-key')
        ->assertCreated();

    expect($first->json('data.id'))->not->toBe($second->json('data.id'));
});

it('AC-050 rotates a key while retaining the previous secret during grace', function () {
    $branch = Branch::factory()->create();
    $service = app(PublicApiKeyService::class);
    $issued = $service->issue($branch, ['name' => 'Rotating key']);
    $rotated = $service->rotate($issued['key']);

    expect($rotated['secret'])->not->toBe($issued['secret'])
        ->and($rotated['key']->previous_key_hash)->toBe(hash('sha256', $issued['secret']))
        ->and($rotated['key']->previous_valid_until)->not->toBeNull()
        ->and($service->resolve($issued['secret']))->not->toBeNull()
        ->and($service->resolve($rotated['secret']))->not->toBeNull();
});

it('audits public API key creation, rotation, and revocation without storing secrets', function () {
    $branch = Branch::factory()->create();
    $actor = User::factory()->create(['branch_id' => $branch->id]);
    $service = app(PublicApiKeyService::class);
    $issued = $service->issue($branch, ['name' => 'Audited key', 'created_by' => $actor->id]);
    $rotated = $service->rotate($issued['key'], 24, $actor->id);
    $auditRows = fn (): array => app(TenantDatabaseContext::class)->run(
        null,
        $branch->tenant_id,
        fn (): array => ApiKeyAuditLog::query()->where('api_key_id', $issued['key']->id)->orderBy('id')->get(['event', 'metadata'])->toArray(),
        actorType: 'test',
    );

    expect($issued['secret'])->toStartWith($issued['key']->prefix)
        ->and($issued['key']->key_hash)->toBe(hash('sha256', $issued['secret']))
        ->and(array_column($auditRows(), 'event'))
        ->toBe(['created', 'rotated']);

    $service->revoke($rotated['key'], $actor->id);

    expect($service->resolve($issued['secret']))->toBeNull()
        ->and($service->resolve($rotated['secret']))->toBeNull()
        ->and(array_column($auditRows(), 'event'))
        ->toBe(['created', 'rotated', 'revoked'])
        ->and(json_encode($auditRows()))
        ->not->toContain($issued['secret']);
});

it('enforces the tenant public API key entitlement limit', function () {
    $branch = Branch::factory()->create();
    $plan = Plan::factory()->create();
    PlanEntitlement::create([
        'plan_id' => $plan->id,
        'entitlement_key' => 'api.public',
        'value_type' => PlanEntitlement::TYPE_BOOLEAN,
        'value' => 'true',
    ]);
    PlanEntitlement::create([
        'plan_id' => $plan->id,
        'entitlement_key' => 'limits.api.keys.max',
        'value_type' => PlanEntitlement::TYPE_INTEGER_LIMIT,
        'value' => '1',
    ]);
    Subscription::factory()->for($branch->tenant)->for($plan)->create();
    $service = app(PublicApiKeyService::class);
    $service->issue($branch, ['name' => 'First']);

    expect(fn () => $service->issue($branch, ['name' => 'Second']))
        ->toThrow(DomainException::class, 'key limit has been reached');
});
