<?php

use App\Models\Branch;
use App\Models\ConciergeAction;
use App\Models\ConciergeMessage;
use App\Models\ConciergeThread;
use App\Services\ConciergeAnalyticsService;
use App\Services\SentimentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create([
        'timezone' => 'Africa/Lagos',
        'currency_code' => 'NGN',
    ]);

    $this->admin = $this->makeAdminUser($this->branch);
});

it('computes the concierge deflection rate from autonomous ai answers', function () {
    $thread = ConciergeThread::factory()->forBranch($this->branch->id)->create([
        'whatsapp_wa_id' => '2348031234567',
    ]);

    ConciergeMessage::factory()->fromGuest()->create([
        'concierge_thread_id' => $thread->id,
        'body' => 'What is the wifi password?',
    ]);

    ConciergeMessage::factory()->assistant()->create([
        'concierge_thread_id' => $thread->id,
        'body' => 'Connect to GUEST-WIFI with password HOTEL2026.',
    ]);

    $summary = (new ConciergeAnalyticsService(new SentimentService))->forBranch($this->branch)->getConciergeSummary();

    expect($summary['deflection'])
        ->toBe([
            'period_days' => 30,
            'ai_handled' => 1,
            'deflected_to_human' => 0,
            'deflection_rate' => 100.0,
        ]);
});

it('reports conversations deflected to a human when the concierge hands off', function () {
    $thread = ConciergeThread::factory()->forBranch($this->branch->id)->handoff()->create([
        'whatsapp_wa_id' => '2348031234567',
    ]);

    ConciergeMessage::factory()->fromGuest()->create([
        'concierge_thread_id' => $thread->id,
        'body' => 'I need to speak to a manager about a complaint.',
    ]);

    $summary = (new ConciergeAnalyticsService(new SentimentService))->forBranch($this->branch)->getDeflectionSummary();

    expect($summary['ai_handled'])->toBe(0)
        ->and($summary['deflected_to_human'])->toBe(1)
        ->and($summary['deflection_rate'])->toBe(0.0);
});

it('computes revenue impact from applied concierge actions', function () {
    $thread = ConciergeThread::factory()->forBranch($this->branch->id)->create([
        'whatsapp_wa_id' => '2348031234567',
    ]);

    ConciergeAction::factory()->create([
        'concierge_thread_id' => $thread->id,
        'type' => ConciergeAction::TYPE_LATE_CHECKOUT,
        'status' => ConciergeAction::STATUS_APPLIED,
        'payload' => ['offer_id' => 1, 'fee_minor' => 5000, 'kind' => 'late_checkout'],
        'applied_at' => now(),
    ]);

    $impact = (new ConciergeAnalyticsService(new SentimentService))->forBranch($this->branch)->getRevenueImpact();

    expect($impact)->toBe([
        'period_days' => 30,
        'actions_applied' => 1,
        'revenue_minor' => 5000,
    ]);
});

it('averages guest sentiment per day across concierge conversations', function () {
    $thread = ConciergeThread::factory()->forBranch($this->branch->id)->create([
        'whatsapp_wa_id' => '2348031234567',
    ]);

    ConciergeMessage::factory()->fromGuest()->create([
        'concierge_thread_id' => $thread->id,
        'body' => 'The room was amazing and the staff were lovely',
    ]);

    ConciergeMessage::factory()->fromGuest()->create([
        'concierge_thread_id' => $thread->id,
        'body' => 'The room was terrible and dirty',
    ]);

    $trend = (new ConciergeAnalyticsService(new SentimentService))->forBranch($this->branch)->getSentimentTrend();

    expect($trend->count())->toBe(1);

    $point = $trend->first();

    expect($point['date'])->toBe(now()->toDateString())
        ->and($point['guest_messages'])->toBe(2)
        ->and($point['avg_sentiment'])->toBe(0.5);
});

it('reports zeroed concierge metrics when the branch has no conversations', function () {
    $summary = (new ConciergeAnalyticsService(new SentimentService))->forBranch($this->branch)->getConciergeSummary();

    expect($summary['deflection'])
        ->toBe([
            'period_days' => 30,
            'ai_handled' => 0,
            'deflected_to_human' => 0,
            'deflection_rate' => 0.0,
        ])
        ->and($summary['revenue_impact']['revenue_minor'])->toBe(0)
        ->and($summary['sentiment_trend']->count())->toBe(0);
});

it('aggregates concierge metrics across the branches of a tenant', function () {
    $tenant = $this->branch->tenant;
    $otherBranch = Branch::factory()->for($tenant)->create();

    $aiThread = ConciergeThread::factory()->forBranch($this->branch->id)->create([
        'whatsapp_wa_id' => '2348031234567',
    ]);

    ConciergeMessage::factory()->fromGuest()->create([
        'concierge_thread_id' => $aiThread->id,
        'body' => 'What is the wifi password?',
    ]);

    ConciergeMessage::factory()->assistant()->create([
        'concierge_thread_id' => $aiThread->id,
        'body' => 'Connect to GUEST-WIFI with password HOTEL2026.',
    ]);

    $deflectedThread = ConciergeThread::factory()->forBranch($otherBranch->id)->handoff()->create([
        'whatsapp_wa_id' => '2348031234568',
    ]);

    ConciergeMessage::factory()->fromGuest()->create([
        'concierge_thread_id' => $deflectedThread->id,
        'body' => 'I need to speak to a manager about a complaint.',
    ]);

    $summary = (new ConciergeAnalyticsService(new SentimentService))->getTenantSummary($tenant->id);

    expect($summary['branches'])->toBe(2)
        ->and($summary['ai_handled'])->toBe(1)
        ->and($summary['deflected_to_human'])->toBe(1)
        ->and($summary['deflection_rate'])->toBe(50.0);
});

it('exposes the concierge roi report through the api', function () {
    $this->actingAs($this->admin)
        ->getJson('/api/v1/reports/concierge')
        ->assertOk()
        ->assertJsonPath('data.branch_id', $this->branch->id)
        ->assertJsonStructure([
            'data' => [
                'branch_id',
                'period_days',
                'deflection',
                'revenue_impact',
                'sentiment_trend',
            ],
        ]);
});
