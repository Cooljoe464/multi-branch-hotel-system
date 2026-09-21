<?php

use App\Events\QueueAlertRaised;
use App\Jobs\MonitorStuckQueuesJob;
use App\Models\Branch;
use App\Models\DailyLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('reports liveness without authentication', function () {
    $this->get('/healthz')->assertOk()->assertJson(['status' => 'ok']);
});

it('reports readiness with check details', function () {
    $response = $this->get('/readyz');

    $response->assertStatus(200)->assertJsonStructure([
        'status',
        'checks' => ['database', 'redis', 'queue'],
    ]);
});

it('exposes failed job counts for monitors', function () {
    $this->get('/health/queues')->assertOk()->assertJsonStructure(['failed_jobs']);
});

it('raises an alert for ledgers stuck in progress', function () {
    Event::fake([QueueAlertRaised::class]);

    $branch = Branch::factory()->create();

    DailyLedger::create([
        'branch_id' => $branch->id,
        'business_date' => now()->subDay()->toDateString(),
        'status' => 'in_progress',
        'started_at' => now()->subHour(),
    ]);

    app(MonitorStuckQueuesJob::class)->handle();

    Event::assertDispatched(QueueAlertRaised::class, fn ($event) => $event->kind === 'night_audit_stuck');
});

it('stays silent when nothing is stuck', function () {
    Event::fake([QueueAlertRaised::class]);

    app(MonitorStuckQueuesJob::class)->handle();

    Event::assertNotDispatched(QueueAlertRaised::class);
});

it('supervises every dedicated queue in horizon config', function () {
    $queues = collect(config('horizon.defaults'))
        ->flatMap(fn ($supervisor) => $supervisor['queue'] ?? [])
        ->unique()
        ->sort()
        ->values()
        ->all();

    foreach (['default', 'night-audit', 'payments', 'channel', 'imports', 'reports', 'maintenance', 'webhooks'] as $queue) {
        expect($queues)->toContain($queue);
    }
});

it('scrubs guest PII from sentry events', function () {
    $beforeSend = config('sentry.before_send');

    expect($beforeSend)->toBeCallable();

    $event = Sentry\Event::createEvent();
    $event->setRequest(['data' => ['guest_email' => 'a@b.com', 'room' => '101']]);

    $scrubbed = $beforeSend($event, null);

    expect($scrubbed->getRequest())->toBe(['data' => ['guest_email' => '[REDACTED]', 'room' => '101']]);
});
