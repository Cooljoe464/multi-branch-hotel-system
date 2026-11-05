<?php

use App\Models\Branch;
use App\Models\ConciergeAction;
use App\Models\ConciergeKbEntry;
use App\Models\ConciergeMessage;
use App\Models\ConciergeThread;
use App\Models\Consent;
use App\Models\Guest;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\UpsellAcceptance;
use App\Models\UpsellOffer;
use App\Services\Concierge\Ai\ConciergeAiProvider;
use App\Services\Concierge\Ai\ConciergeAiResponse;
use App\Services\ConciergeService;
use App\Services\RateEngine;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function conciergeBranch(array $overrides = []): Branch
{
    $whatsapp = [
        'enabled' => true,
        'phone_number_id' => '123456789012345',
        'waba_id' => '098765432109876',
        'verify_token' => 'verify-me',
        'access_token_enc' => 'enc:'.Crypt::encryptString('test-access-token'),
        'app_secret_enc' => 'enc:'.Crypt::encryptString('test-app-secret'),
    ];

    return Branch::factory()->create(array_merge([
        'timezone' => 'Africa/Lagos',
        'currency_code' => 'NGN',
        'settings' => ['whatsapp' => $whatsapp],
    ], $overrides));
}

/**
 * @return array<string, mixed>
 */
function conciergePayload(string $phoneNumberId, string $from, string $messageId, string $body, string $name = 'Ada Guest'): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => 'waba-1',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => [
                        'display_phone_number' => '2348000000000',
                        'phone_number_id' => $phoneNumberId,
                    ],
                    'contacts' => [[
                        'profile' => ['name' => $name],
                        'wa_id' => $from,
                    ]],
                    'messages' => [[
                        'from' => $from,
                        'id' => $messageId,
                        'timestamp' => (string) now()->timestamp,
                        'type' => 'text',
                        'text' => ['body' => $body],
                    ]],
                ],
            ]],
        ]],
    ];
}

function conciergeSend(object $test, array $payload, string $secret = 'test-app-secret'): TestResponse
{
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = 'sha256='.hash_hmac('sha256', $raw, $secret);

    return $test->call('POST', '/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $signature,
    ], $raw);
}

beforeEach(function () {
    $this->branch = conciergeBranch();
    $this->admin = $this->makeAdminUser($this->branch);
    $this->phoneNumberId = '123456789012345';
    $this->waId = '2348031234567';

    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.outbound']]], 200),
        // No concierge AI provider is configured in tests; the orchestrator
        // must fall back to the deterministic heuristic driver.
        'api.openai.com/*' => Http::response('', 500),
    ]);
});

it('completes the Meta webhook verification challenge with the branch token', function () {
    $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=challenge-123')
        ->assertOk()
        ->assertSee('challenge-123');

    $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=challenge-123')
        ->assertForbidden();
});

it('answers a FAQ from the branch knowledge base and discloses the AI assistant', function () {
    ConciergeKbEntry::create([
        'branch_id' => $this->branch->id,
        'topic' => 'Wi-Fi',
        'question' => 'What is the Wi-Fi password?',
        'answer' => 'Connect to GUEST-WIFI with password HOTEL2026.',
        'keywords' => ['wifi', 'internet', 'password'],
        'active' => true,
    ]);

    conciergeSend($this, conciergePayload($this->phoneNumberId, $this->waId, 'wamid.faq.1', 'What is the wifi password?'))
        ->assertOk();

    $thread = ConciergeThread::where('whatsapp_wa_id', $this->waId)->firstOrFail();
    $reply = $thread->messages()->where('role', ConciergeMessage::ROLE_ASSISTANT)->firstOrFail();

    expect($reply->body)
        ->toContain('HOTEL2026')
        ->toContain('AI assistant');

    expect($thread->messages()->where('role', ConciergeMessage::ROLE_GUEST)->count())->toBe(1);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/123456789012345/messages')
        && $request['to'] === $this->waId
        && str_contains((string) $request['text']['body'], 'HOTEL2026'));
});

it('quotes the exact live RateEngine total and never invents a price', function () {
    $roomType = RoomType::factory()->create([
        'branch_id' => $this->branch->id,
        'base_rate' => 10000,
    ]);

    $plan = RatePlan::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $roomType->id,
        'code' => 'BAR',
        'rate_multiplier' => 1.0,
        'valid_from' => now()->subDay()->toDateString(),
        'is_active' => true,
    ]);

    $guest = Guest::factory()->create(['phone' => $this->waId]);

    $reservation = Reservation::factory()->create([
        'branch_id' => $this->branch->id,
        'guest_id' => $guest->id,
        'guest_name' => $guest->full_name,
        'guest_phone' => $this->waId,
        'room_type_id' => $roomType->id,
        'rate_plan_id' => $plan->id,
        'status' => 'confirmed',
        'check_in_date' => now()->addDay()->toDateString(),
        'check_out_date' => now()->addDays(3)->toDateString(),
    ]);

    $quote = (new RateEngine)->price(
        $this->branch,
        $plan,
        $roomType,
        $reservation->check_in_date->toDateString(),
        $reservation->check_out_date->toDateString(),
    );

    $expected = number_format($quote['total_minor'] / 100, 2).' '.$this->branch->currency_code;

    conciergeSend($this, conciergePayload($this->phoneNumberId, $this->waId, 'wamid.rate.1', 'How much is my stay total?'))
        ->assertOk();

    $thread = ConciergeThread::where('whatsapp_wa_id', $this->waId)->firstOrFail();
    $reply = $thread->messages()->where('role', ConciergeMessage::ROLE_ASSISTANT)->firstOrFail();

    expect($reply->body)->toContain($expected);
    expect($reply->tool_calls[0]['name'] ?? null)->toBe('quote_rate');
});

it('creates one pending draft for a money action and never applies it', function () {
    $roomType = RoomType::factory()->create([
        'branch_id' => $this->branch->id,
        'base_rate' => 10000,
    ]);

    Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $roomType->id,
        'status' => 'occupied',
    ]);

    $guest = Guest::factory()->create(['phone' => $this->waId]);

    $reservation = Reservation::factory()->checkedIn()->create([
        'branch_id' => $this->branch->id,
        'guest_id' => $guest->id,
        'guest_phone' => $this->waId,
        'room_type_id' => $roomType->id,
        'check_in_date' => now()->subDay()->toDateString(),
        'check_out_date' => now()->addDay()->toDateString(),
    ]);

    $room = Room::where('branch_id', $this->branch->id)->firstOrFail();
    $reservation->update(['room_id' => $room->id]);

    UpsellOffer::create([
        'branch_id' => $this->branch->id,
        'kind' => UpsellOffer::KIND_LATE_CHECKOUT,
        'rules' => ['fee_minor' => 5000, 'inventory_guard' => 1],
        'active' => true,
    ]);

    $payload = conciergePayload($this->phoneNumberId, $this->waId, 'wamid.money.1', 'Can I get a late checkout?');

    conciergeSend($this, $payload)->assertOk();

    $thread = ConciergeThread::where('whatsapp_wa_id', $this->waId)->firstOrFail();
    $action = ConciergeAction::where('concierge_thread_id', $thread->id)->firstOrFail();

    expect($action->type)->toBe(ConciergeAction::TYPE_LATE_CHECKOUT);
    expect($action->status)->toBe(ConciergeAction::STATUS_PENDING_GUEST_CONFIRM);
    expect($action->payload['fee_minor'] ?? null)->toBe(5000);
    expect(UpsellAcceptance::count())->toBe(0);
    expect($thread->status)->toBe(ConciergeThread::STATUS_HANDOFF);

    // Meta redelivers the same message id, then a second identical request
    // arrives. Neither may create a second draft.
    conciergeSend($this, $payload)->assertOk();
    conciergeSend($this, conciergePayload($this->phoneNumberId, $this->waId, 'wamid.money.2', 'Can I get a late checkout please?'))
        ->assertOk();

    expect(ConciergeAction::where('concierge_thread_id', $thread->id)->count())->toBe(1);
    expect(UpsellAcceptance::count())->toBe(0);
});

it('blocks marketing without consent but keeps service replies flowing', function () {
    $guest = Guest::factory()->create(['phone' => $this->waId]);

    $thread = ConciergeThread::factory()->create([
        'branch_id' => $this->branch->id,
        'guest_id' => $guest->id,
        'whatsapp_wa_id' => $this->waId,
    ]);

    $service = ConciergeService::make();

    expect($service->sendMarketing($thread, 'Festive offer just for you!'))->toBeFalse();
    Http::assertNothingSent();

    Consent::create([
        'guest_id' => $guest->id,
        'channel' => 'whatsapp',
        'purpose' => 'marketing',
        'granted' => true,
        'at' => now(),
    ]);

    expect($service->sendMarketing($thread, 'Festive offer just for you!'))->toBeTrue();

    ConciergeKbEntry::create([
        'branch_id' => $this->branch->id,
        'topic' => 'Breakfast',
        'question' => 'What time is breakfast?',
        'answer' => 'Breakfast is served from 06:30 to 10:30.',
        'keywords' => ['breakfast', 'hours'],
        'active' => true,
    ]);

    conciergeSend($this, conciergePayload($this->phoneNumberId, $this->waId, 'wamid.consent.1', 'What time is breakfast?'))
        ->assertOk();

    expect(ConciergeMessage::where('concierge_thread_id', $thread->id)
        ->where('role', ConciergeMessage::ROLE_ASSISTANT)
        ->where('body', 'like', '%06:30%')
        ->exists())->toBeTrue();
});

it('switches to human-only mode instantly when the branch kill switch is off', function () {
    $this->branch->update([
        'settings' => [
            'whatsapp' => array_merge(
                (new WhatsAppCloudApiService)->settings($this->branch),
                ['enabled' => false],
            ),
        ],
    ]);

    ConciergeKbEntry::create([
        'branch_id' => $this->branch->id,
        'topic' => 'Wi-Fi',
        'answer' => 'Connect to GUEST-WIFI.',
        'keywords' => ['wifi'],
        'active' => true,
    ]);

    conciergeSend($this, conciergePayload($this->phoneNumberId, $this->waId, 'wamid.kill.1', 'What is the wifi password?'))
        ->assertOk();

    $thread = ConciergeThread::where('whatsapp_wa_id', $this->waId)->firstOrFail();

    expect($thread->status)->toBe(ConciergeThread::STATUS_HANDOFF);
    expect($thread->messages()->where('role', ConciergeMessage::ROLE_GUEST)->count())->toBe(1);
    expect($thread->messages()->where('role', ConciergeMessage::ROLE_ASSISTANT)->count())->toBe(0);
    Http::assertNothingSent();
});

it('answers from the branch knowledge base through the model-backed concierge driver', function () {
    $entry = ConciergeKbEntry::create([
        'branch_id' => $this->branch->id,
        'topic' => 'Wi-Fi',
        'question' => 'What is the Wi-Fi password?',
        'answer' => 'Connect to GUEST-WIFI with password HOTEL2026.',
        'keywords' => ['wifi', 'internet', 'password'],
        'active' => true,
    ]);

    $fake = new class($entry->id) implements ConciergeAiProvider
    {
        public function __construct(private int $entryId) {}

        public function complete(string $systemPrompt, array $messages, ?array $tools = null, ?string $model = null): ConciergeAiResponse
        {
            return new ConciergeAiResponse(json_encode([
                'intent' => 'faq',
                'confidence' => 0.9,
                'kb_entry_id' => $this->entryId,
                'args' => [],
            ]));
        }
    };

    $service = ConciergeService::make($fake);

    $inbound = $service->handleInbound($this->branch, [
        'from' => $this->waId,
        'id' => 'wamid.ai.1',
        'type' => 'text',
        'text' => ['body' => 'What is the wifi password?'],
    ]);

    expect($inbound)->not->toBeNull();

    $thread = ConciergeThread::where('whatsapp_wa_id', $this->waId)->firstOrFail();
    $reply = $thread->messages()->where('role', ConciergeMessage::ROLE_ASSISTANT)->firstOrFail();

    expect($reply->body)
        ->toContain('GUEST-WIFI')
        ->toContain('AI assistant');

    expect($thread->messages()->where('role', ConciergeMessage::ROLE_GUEST)->count())->toBe(1);
});

it('rejects webhook payloads with an invalid signature', function () {
    $payload = conciergePayload($this->phoneNumberId, $this->waId, 'wamid.bad.1', 'Hello');

    conciergeSend($this, $payload, 'wrong-secret')->assertUnauthorized();

    expect(ConciergeMessage::count())->toBe(0);
});
