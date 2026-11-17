<?php

use App\Exceptions\UnsafeWebhookUrl;
use App\Jobs\DeliverWebhookJob;
use App\Models\ApiConsumer;
use App\Models\Branch;
use App\Models\WebhookDelivery;
use App\Services\WebhookDispatcher;
use App\Services\WebhookSignature;
use App\Services\WebhookUrlGuard;
use App\Support\Network\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * @param  list<string>  $addresses
 */
function resolvingTo(array $addresses): DnsResolver
{
    return new class($addresses) extends DnsResolver
    {
        /** @param list<string> $addresses */
        public function __construct(private array $addresses) {}

        public function resolve(string $host): array
        {
            return $this->addresses;
        }
    };
}

describe('destination safety (AC-051)', function () {
    test('accepts an HTTPS URL that resolves to a public address', function () {
        $destination = (new WebhookUrlGuard(resolvingTo(['93.184.216.34'])))->resolve('https://hooks.example.com/pms');

        expect($destination)->toBe(['host' => 'hooks.example.com', 'port' => 443, 'addresses' => ['93.184.216.34']]);
    });

    test('rejects plain HTTP', function () {
        expect(fn () => (new WebhookUrlGuard(resolvingTo(['93.184.216.34'])))->resolve('http://hooks.example.com'))
            ->toThrow(UnsafeWebhookUrl::class, 'HTTPS');
    });

    test('rejects embedded credentials', function () {
        expect(fn () => (new WebhookUrlGuard(resolvingTo(['93.184.216.34'])))->resolve('https://user:pass@hooks.example.com'))
            ->toThrow(UnsafeWebhookUrl::class, 'credentials');
    });

    test('rejects a host that does not resolve', function () {
        expect(fn () => (new WebhookUrlGuard(resolvingTo([])))->resolve('https://nowhere.example.com'))
            ->toThrow(UnsafeWebhookUrl::class, 'does not resolve');
    });

    test('rejects a non-public address', function (string $address) {
        expect(fn () => (new WebhookUrlGuard(resolvingTo([$address])))->resolve('https://hooks.example.com'))
            ->toThrow(UnsafeWebhookUrl::class, 'non-public');
    })->with([
        'loopback' => '127.0.0.1',
        'private 10/8' => '10.0.0.5',
        'private 172.16/12' => '172.16.4.9',
        'private 192.168/16' => '192.168.1.20',
        'cloud metadata link-local' => '169.254.169.254',
        'carrier-grade NAT' => '100.64.0.1',
        'unspecified' => '0.0.0.0',
        'multicast' => '224.0.0.1',
        'documentation range' => '203.0.113.7',
        'IPv6 loopback' => '::1',
        'IPv6 unique local' => 'fc00::1',
        'IPv6 link-local' => 'fe80::1',
        'IPv4-mapped private' => '::ffff:10.0.0.1',
        'NAT64-embedded loopback' => '64:ff9b::7f00:1',
    ]);

    test('rejects a host with any non-public address among its answers', function () {
        expect(fn () => (new WebhookUrlGuard(resolvingTo(['93.184.216.34', '10.0.0.5'])))->resolve('https://hooks.example.com'))
            ->toThrow(UnsafeWebhookUrl::class, 'non-public');
    });

    test('rejects a registration whose host resolves to a private address', function () {
        $branch = Branch::factory()->create();
        $admin = $this->makeAdminUser($branch);
        app()->instance(DnsResolver::class, resolvingTo(['10.0.0.5']));

        $this->actingAs($admin)
            ->post('/developers/consumers', [
                'name' => 'Sneaky',
                'branch_id' => $branch->id,
                'scopes' => ['availability.view'],
                'webhook_url' => 'https://internal.example.com/hook',
            ])
            ->assertSessionHasErrors('webhook_url');

        expect(ApiConsumer::query()->count())->toBe(0);
    });

    test('blocks delivery when DNS later re-resolves to a private address', function () {
        Http::fake();
        $branch = Branch::factory()->create();
        $consumer = ApiConsumer::create([
            'name' => 'Rebinder',
            'branch_id' => $branch->id,
            'scopes' => ['availability.view'],
            'webhook_url' => 'https://rebind.example.com/hook',
            'webhook_secret' => str_repeat('b', 64),
            'is_active' => true,
        ]);
        $delivery = WebhookDelivery::create([
            'api_consumer_id' => $consumer->id,
            'event' => 'reservation.created',
            'payload' => ['event' => 'reservation.created'],
            'signature' => 'sha256=x',
            'status' => WebhookDelivery::STATUS_PENDING,
            'attempts' => 0,
        ]);
        app()->instance(DnsResolver::class, resolvingTo(['169.254.169.254']));

        (new DeliverWebhookJob($delivery->id, $consumer->id))->handle();

        Http::assertNothingSent();
        expect($delivery->fresh()?->status)->toBe(WebhookDelivery::STATUS_FAILED)
            ->and($delivery->fresh()?->last_error)->toContain('non-public');
    });

    test('does not follow a webhook redirect to an unchecked destination', function () {
        Http::fake(['partner.example/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/latest/meta-data'])]);
        $branch = Branch::factory()->create();
        $consumer = ApiConsumer::create([
            'name' => 'Redirector',
            'branch_id' => $branch->id,
            'scopes' => ['availability.view'],
            'webhook_url' => 'https://partner.example/hooks',
            'webhook_secret' => str_repeat('d', 64),
            'is_active' => true,
        ]);
        $delivery = WebhookDelivery::create([
            'api_consumer_id' => $consumer->id,
            'event' => 'reservation.created',
            'payload' => ['event' => 'reservation.created'],
            'signature' => 'sha256=x',
            'status' => WebhookDelivery::STATUS_PENDING,
            'attempts' => 0,
        ]);

        expect(fn () => (new DeliverWebhookJob($delivery->id, $consumer->id))->handle())
            ->toThrow(RuntimeException::class, 'Receiver responded 302.');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://partner.example/hooks');
    });
});

describe('timestamped signatures (AC-050)', function () {
    test('verifies a fresh signature made with the current secret', function () {
        $header = WebhookSignature::header('{"a":1}', 'secret-one', 1_800_000_000);

        expect(WebhookSignature::verify($header, '{"a":1}', ['secret-one'], now: 1_800_000_100))->toBeTrue();
    });

    test('rejects a stale timestamp', function () {
        $header = WebhookSignature::header('{"a":1}', 'secret-one', 1_800_000_000);

        expect(WebhookSignature::verify($header, '{"a":1}', ['secret-one'], now: 1_800_000_000 + 301))->toBeFalse();
    });

    test('rejects a timestamp too far in the future', function () {
        $header = WebhookSignature::header('{"a":1}', 'secret-one', 1_800_000_000 + 400);

        expect(WebhookSignature::verify($header, '{"a":1}', ['secret-one'], now: 1_800_000_000))->toBeFalse();
    });

    test('accepts the edge of the five minute tolerance', function () {
        $header = WebhookSignature::header('{"a":1}', 'secret-one', 1_800_000_000);

        expect(WebhookSignature::verify($header, '{"a":1}', ['secret-one'], now: 1_800_000_000 + 300))->toBeTrue();
    });

    test('rejects a replay of the signature over a different body', function () {
        $header = WebhookSignature::header('{"amount":100}', 'secret-one', 1_800_000_000);

        expect(WebhookSignature::verify($header, '{"amount":999}', ['secret-one'], now: 1_800_000_000))->toBeFalse();
    });

    test('rejects the wrong secret and malformed headers', function (string $header) {
        expect(WebhookSignature::verify($header, '{"a":1}', ['secret-one'], now: 1_800_000_000))->toBeFalse();
    })->with([
        'wrong secret' => fn () => WebhookSignature::header('{"a":1}', 'other-secret', 1_800_000_000),
        'empty' => '',
        'missing version' => 't=1800000000',
        'bad hex' => 't=1800000000,v1=zzzz',
    ]);

    test('both the new and the previous secret verify during a rotation overlap', function () {
        $consumer = ApiConsumer::create([
            'name' => 'Rotator',
            'branch_id' => Branch::factory()->create()->id,
            'scopes' => ['availability.view'],
            'webhook_secret' => 'old-secret-'.str_repeat('o', 20),
            'is_active' => true,
        ]);
        $oldHeader = WebhookSignature::header('{"a":1}', 'old-secret-'.str_repeat('o', 20), 1_800_000_000);

        (new WebhookDispatcher)->rotateSecret($consumer);
        $consumer->refresh();
        $newHeader = WebhookSignature::header('{"a":1}', $consumer->webhook_secret ?? '', 1_800_000_000);

        expect(WebhookSignature::verify($oldHeader, '{"a":1}', $consumer->webhookSecrets(), now: 1_800_000_000))->toBeTrue()
            ->and(WebhookSignature::verify($newHeader, '{"a":1}', $consumer->webhookSecrets(), now: 1_800_000_000))->toBeTrue();
    });

    test('the previous secret stops verifying once the overlap ends', function () {
        $consumer = ApiConsumer::create([
            'name' => 'Rotator',
            'branch_id' => Branch::factory()->create()->id,
            'scopes' => ['availability.view'],
            'webhook_secret' => 'old-secret-'.str_repeat('o', 20),
            'is_active' => true,
        ]);
        $oldHeader = WebhookSignature::header('{"a":1}', 'old-secret-'.str_repeat('o', 20), 1_800_000_000);

        (new WebhookDispatcher)->rotateSecret($consumer);
        $consumer->update(['webhook_grace_until' => now()->subMinute()]);

        expect(WebhookSignature::verify($oldHeader, '{"a":1}', $consumer->fresh()?->webhookSecrets() ?? [], now: 1_800_000_000))->toBeFalse();
    });

    test('delivery carries a signature a receiver can verify over the exact body', function () {
        Http::fake(['partner.example/*' => Http::response('ok', 200)]);
        $secret = str_repeat('c', 64);
        $consumer = ApiConsumer::create([
            'name' => 'Verifier',
            'branch_id' => Branch::factory()->create()->id,
            'scopes' => ['availability.view'],
            'webhook_url' => 'https://partner.example/hooks',
            'webhook_secret' => $secret,
            'is_active' => true,
        ]);
        $delivery = WebhookDelivery::create([
            'api_consumer_id' => $consumer->id,
            'event' => 'reservation.created',
            'payload' => ['event' => 'reservation.created', 'url' => 'https://a.test/x', 'name' => 'Zoë'],
            'signature' => 'sha256=x',
            'status' => WebhookDelivery::STATUS_PENDING,
            'attempts' => 0,
        ]);

        (new DeliverWebhookJob($delivery->id, $consumer->id))->handle();

        Http::assertSent(function ($request) use ($secret) {
            $header = $request->header(WebhookSignature::HEADER)[0] ?? '';

            return WebhookSignature::verify($header, $request->body(), [$secret]);
        });
    });
});
