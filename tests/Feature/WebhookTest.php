<?php

use App\Jobs\DeliverWebhookJob;
use App\Models\ApiConsumer;
use App\Models\Branch;
use App\Models\WebhookDelivery;
use App\Services\WebhookDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['timezone' => 'Africa/Lagos']);
    $this->consumer = ApiConsumer::create([
        'name' => 'Hook Partner',
        'branch_id' => $this->branch->id,
        'scopes' => ['reservations.create'],
        'webhook_url' => 'https://partner.example/hooks/pms',
        'webhook_secret' => str_repeat('a', 64),
        'is_active' => true,
    ]);
    $this->dispatcher = new WebhookDispatcher;
});

it('signs deliveries so a receiver can verify HMAC', function () {
    Queue::fake();

    [$delivery] = $this->dispatcher->dispatch('reservation.created', ['reservation_id' => 1], $this->branch->id);

    $expected = WebhookDispatcher::sign(
        WebhookDispatcher::encode($delivery->payload),
        str_repeat('a', 64)
    );

    expect($delivery->signature)->toBe($expected)
        ->and($delivery->status)->toBe(WebhookDelivery::STATUS_PENDING);

    Queue::assertPushed(DeliverWebhookJob::class);
});

it('delivers with signature headers and marks delivered', function () {
    Http::fake(['partner.example/*' => Http::response('ok', 200)]);

    $delivery = WebhookDelivery::create([
        'api_consumer_id' => $this->consumer->id,
        'event' => 'reservation.created',
        'payload' => ['event' => 'reservation.created', 'reservation_id' => 1],
        'signature' => 'sha256=test',
        'status' => WebhookDelivery::STATUS_PENDING,
        'attempts' => 0,
    ]);

    (new DeliverWebhookJob($delivery->id, $this->consumer->id))->handle();

    Http::assertSent(fn ($request) => $request->header('X-Webhook-Signature') === ['sha256=test']
        && $request->header('X-Webhook-Event') === ['reservation.created']);

    expect($delivery->fresh()?->status)->toBe(WebhookDelivery::STATUS_DELIVERED);
});

it('replays the frozen payload with the same signature', function () {
    Http::fake(['partner.example/*' => Http::response('ok', 200)]);

    $delivery = WebhookDelivery::create([
        'api_consumer_id' => $this->consumer->id,
        'event' => 'reservation.created',
        'payload' => ['event' => 'reservation.created', 'reservation_id' => 7],
        'signature' => 'sha256=stale',
        'status' => WebhookDelivery::STATUS_FAILED,
        'attempts' => 8,
    ]);

    $original = $this->dispatcher->replay($delivery);

    $expected = WebhookDispatcher::sign(
        WebhookDispatcher::encode(['event' => 'reservation.created', 'reservation_id' => 7]),
        str_repeat('a', 64)
    );

    expect($original->signature)->toBe($expected)
        ->and($original->status)->toBe(WebhookDelivery::STATUS_DELIVERED)
        ->and($original->attempts)->toBe(1);
});

it('accepts the previous secret during rotation grace only', function () {
    $this->consumer->update([
        'webhook_previous_secret' => str_repeat('b', 64),
        'webhook_grace_until' => now()->addDay(),
    ]);

    expect($this->consumer->fresh()?->webhookSecrets())->toBe([str_repeat('a', 64), str_repeat('b', 64)]);

    $this->consumer->update(['webhook_grace_until' => now()->subMinute()]);

    expect($this->consumer->fresh()?->webhookSecrets())->toBe([str_repeat('a', 64)]);
});

it('skips consumers without a webhook url', function () {
    Queue::fake();

    ApiConsumer::create([
        'name' => 'No Hooks',
        'branch_id' => $this->branch->id,
        'scopes' => ['reservations.create'],
        'is_active' => true,
    ]);

    $deliveries = $this->dispatcher->dispatch('reservation.created', ['reservation_id' => 1], $this->branch->id);

    expect($deliveries)->toHaveCount(1);
});
