<?php

use App\Http\Middleware\RequireIdempotencyKey;
use App\Models\Branch;
use App\Services\BusinessDateService;
use App\Services\IdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->service = app(IdempotencyService::class);
});

it('executes work once and replays the stored response', function () {
    $calls = 0;

    $first = $this->service->run('test.scope', 'key-1', function () use (&$calls) {
        $calls++;

        return ['ok' => true];
    });

    $second = $this->service->run('test.scope', 'key-1', function () use (&$calls) {
        $calls++;

        return ['ok' => true];
    });

    expect($first['replayed'])->toBeFalse()
        ->and($second['replayed'])->toBeTrue()
        ->and($calls)->toBe(1)
        ->and($second['response'])->toBe(['ok' => true]);
});

it('rejects the same key with a different payload', function () {
    $this->service->run('test.scope', 'key-2', fn () => 'a', null, ['body' => 'aaa']);

    $this->service->run('test.scope', 'key-2', fn () => 'b', null, ['body' => 'bbb']);
})->throws(UnprocessableEntityHttpException::class);

it('blocks a replayed mutating request instead of re-executing it', function () {
    $user = $this->makeAdminUser($this->branch);
    $key = (string) Str::uuid();

    app(BusinessDateService::class)->current($this->branch);

    $this->actingAs($user)
        ->post("/branches/{$this->branch->id}/business-date/advance", [], ['X-Idempotency-Key' => $key])
        ->assertRedirect();

    $firstDate = $this->branch->fresh()->current_business_date->toDateString();

    // Same key again: suppressed, date unchanged.
    $this->actingAs($user)
        ->post("/branches/{$this->branch->id}/business-date/advance", [], ['X-Idempotency-Key' => $key])
        ->assertConflict();

    expect($this->branch->fresh()->current_business_date->toDateString())->toBe($firstDate);

    // Fresh key: advances again.
    $this->actingAs($user)
        ->post("/branches/{$this->branch->id}/business-date/advance", [], ['X-Idempotency-Key' => (string) Str::uuid()])
        ->assertRedirect();

    expect($this->branch->fresh()->current_business_date->toDateString())->not->toBe($firstDate);
});

it('requires a key on mutating routes and ignores safe methods', function () {
    $user = $this->makeAdminUser($this->branch);

    // No header at all: the TestCase funnel injects one, so build the
    // request manually to prove the middleware rejects bare requests.
    $request = Request::create(
        "/branches/{$this->branch->id}/business-date/advance", 'POST'
    );
    $middleware = app(RequireIdempotencyKey::class);

    try {
        $middleware->handle($request, fn () => response('ok'));
        $this->fail('Expected a 422 abort for a missing idempotency key.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(422);
    }

    // GET without a key passes through.
    $get = Request::create('/', 'GET');
    $response = $middleware->handle($get, fn () => response('ok'));
    expect($response->getStatusCode())->toBe(200);
});

it('marks failed work so a retry can re-execute', function () {
    try {
        $this->service->run('test.scope', 'key-3', function () {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    $calls = 0;
    $retry = $this->service->run('test.scope', 'key-3', function () use (&$calls) {
        $calls++;

        return 'recovered';
    });

    expect($retry['replayed'])->toBeFalse()->and($calls)->toBe(1);
});

it('processes a retried paystack webhook once', function () {
    $secret = 'test-secret';
    config(['services.paystack.secret_key' => $secret]);

    $payload = ['event' => 'charge.success', 'data' => ['reference' => 'HMS-TEST123']];
    $content = json_encode($payload);
    $signature = hash_hmac('sha512', $content, $secret);

    $makeCall = fn () => $this->call(
        'POST', '/api/webhooks/paystack', [], [],
        [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X-PAYSTACK-SIGNATURE' => $signature], $content
    );

    // Unknown reference: processed (no-op) the first time...
    $makeCall()->assertOk()->assertJson(['replayed' => false]);
    // ...and replayed the second time without re-executing.
    $makeCall()->assertOk()->assertJson(['replayed' => true]);

    $this->assertDatabaseCount('idempotency_keys', 1);
});
