<?php

use App\Events\ChannelPushFailed;
use App\Events\ParityAlertRaised;
use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\ChannelMapping;
use App\Models\ChannelMessage;
use App\Models\ChannelProviderModel;
use App\Models\ChannelReconciliationRun;
use App\Models\ChannelReservation;
use App\Models\PaymentMethod;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationNight;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\AvailabilityService;
use App\Services\Channels\FailingChannelDriver;
use App\Services\Channels\LogChannelDriver;
use App\Services\ChannelService;
use Carbon\Carbon;
use Database\Seeders\ChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['timezone' => 'Africa/Lagos']);
    $this->user = $this->makeAdminUser($this->branch);
    $this->seed(ChartSeeder::class);
    $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id, 'base_rate' => 10000]);
    $this->room = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'status' => 'available',
    ]);
    $this->plan = RatePlan::factory()->bar()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => null,
        'rate_multiplier' => 1.0,
    ]);
    $this->provider = ChannelProviderModel::create([
        'branch_id' => $this->branch->id,
        'provider' => 'bookingcom',
        'api_secret' => 'test-secret',
        'settings' => ['driver' => 'log'],
        'is_active' => true,
    ]);
    $this->mapping = ChannelMapping::create([
        'branch_id' => $this->branch->id,
        'channel_provider_id' => $this->provider->id,
        'channel' => 'bookingcom',
        'room_type_id' => $this->roomType->id,
        'rate_plan_id' => $this->plan->id,
        'channel_room_code' => 'DLX',
        'channel_rate_code' => 'BAR',
    ]);
    $this->service = app(ChannelService::class);

    LogChannelDriver::flush();
    FailingChannelDriver::failTimes(2);
});

function inboundPayload(string $booking, string $in, string $out): array
{
    return [
        'channel_booking_id' => $booking,
        'channel_room_code' => 'DLX',
        'channel_rate_code' => 'BAR',
        'check_in' => $in,
        'check_out' => $out,
        'guest_name' => 'OTA Guest',
        'guest_email' => 'ota@example.com',
        'adults' => 2,
        'virtual_card' => [
            'token' => 'vc-token-123',
            'brand' => 'Mastercard',
            'last4' => '4444',
            'pan' => '5555444433331111',
            'cvv' => '123',
        ],
    ];
}

function postInbound(ChannelProviderModel $provider, array $payload): TestResponse
{
    $raw = json_encode($payload);
    $signature = hash_hmac('sha256', $raw, 'test-secret');

    return test()->call(
        'POST',
        '/api/webhooks/channels/'.$provider->id,
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X-CHANNEL-SIGNATURE' => $signature],
        $raw,
    );
}

it('retries ARI pushes and applies a single OTA update', function () {
    Event::fake([ChannelPushFailed::class]);

    $this->provider->update(['settings' => ['driver' => 'failing']]);

    $message = $this->service->queueAri(
        $this->branch, $this->provider, 'bookingcom',
        ['channel_room_code' => 'DLX', 'stay_date' => '2026-12-01', 'sellable' => 1, 'rate_minor' => 10000],
        'ari.test.retry-1',
    );

    expect($this->service->sendMessage($message))->toBeFalse();
    expect($this->service->sendMessage($message->fresh()))->toBeFalse();
    expect($this->service->sendMessage($message->fresh()))->toBeTrue();

    $message->refresh();

    expect($message->status)->toBe(ChannelMessage::STATUS_ACKED)
        ->and($message->attempts)->toBe(3)
        ->and(LogChannelDriver::sentFor('ari.test.retry-1'))->toHaveCount(1)
        ->and(LogChannelDriver::effective())->toHaveCount(1);

    Event::assertDispatched(ChannelPushFailed::class, 2);
});

it('replays failed messages under the same key without duplicating', function () {
    $message = $this->service->queueAri(
        $this->branch, $this->provider, 'bookingcom',
        ['channel_room_code' => 'DLX', 'stay_date' => '2026-12-01', 'sellable' => 1, 'rate_minor' => 10000],
        'ari.test.replay-1',
    );
    $message->update(['status' => ChannelMessage::STATUS_FAILED, 'last_error' => 'boom']);

    $requeued = $this->service->replay($message);

    expect($requeued->status)->toBe(ChannelMessage::STATUS_QUEUED)
        ->and($requeued->idempotency_key)->toBe('ari.test.replay-1')
        ->and(ChannelMessage::count())->toBe(1);

    expect($this->service->sendMessage($requeued))->toBeTrue();

    expect(ChannelMessage::count())->toBe(1)
        ->and(LogChannelDriver::effective())->toHaveCount(1);

    $requeued->refresh();

    expect(fn () => $this->service->replay($requeued))
        ->toThrow(AvailabilityException::class, 'Already-acked');
});

it('books inbound webhooks exactly once across duplicate deliveries', function () {
    $in = Carbon::now()->addDays(5)->toDateString();
    $out = Carbon::now()->addDays(7)->toDateString();

    $first = postInbound($this->provider, inboundPayload('OTA-1', $in, $out));
    $second = postInbound($this->provider, inboundPayload('OTA-1', $in, $out));

    $first->assertCreated();
    $second->assertCreated();

    expect($first->json('confirmation_number'))->toBe($second->json('confirmation_number'));
    expect(ChannelReservation::count())->toBe(1);
    expect(Reservation::forBranch($this->branch->id)->count())->toBe(1);
    expect(ReservationNight::count())->toBe(2);
    expect(app(AvailabilityService::class)->sellableFor($this->branch, $this->roomType, $in))->toBe(0);

    $stored = ChannelReservation::first()->raw_payload;

    expect($stored['virtual_card']['pan'] ?? null)->toBeNull()
        ->and($stored['virtual_card']['token'] ?? null)->toBe('vc-token-123');

    $method = PaymentMethod::first();

    expect($method->token)->toBe('vc-token-123')
        ->and($method->last4)->toBe('4444');

    // No PAN anywhere in the database row.
    expect(json_encode($stored))->not->toContain('5555444433331111');
});

it('rejects webhooks with bad signatures or unknown codes', function () {
    $in = Carbon::now()->addDays(5)->toDateString();
    $out = Carbon::now()->addDays(7)->toDateString();
    $payload = inboundPayload('OTA-BAD', $in, $out);
    $raw = json_encode($payload);

    test()->call(
        'POST',
        '/api/webhooks/channels/'.$this->provider->id,
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X-CHANNEL-SIGNATURE' => 'wrong'],
        $raw,
    )->assertUnauthorized();

    $payload['channel_rate_code'] = 'UNKNOWN';

    postInbound($this->provider, $payload)->assertUnprocessable();

    expect(Reservation::forBranch($this->branch->id)->count())->toBe(0);
});

it('flags parity drift, alerts and auto-repushes', function () {
    Event::fake([ParityAlertRaised::class]);

    $from = Carbon::tomorrow()->toDateString();
    $to = Carbon::tomorrow()->addDays(2)->toDateString();

    // PMS has 1 sellable at 10000; the OTA claims 0 at 9000.
    $rows = [];
    foreach (app(AvailabilityService::class)->nights($from, $to) as $date) {
        $rows[] = ['stay_date' => $date, 'channel_room_code' => 'DLX', 'sellable' => 0, 'rate_minor' => 9000];
    }
    LogChannelDriver::stageInventory($this->branch->id, $rows);

    $result = $this->service->reconcile($this->provider, $from, $to);

    expect($result['drifted'])->toBeGreaterThan(0);

    $runs = ChannelReconciliationRun::forBranch($this->branch->id)->get();

    expect($runs->pluck('status')->unique()->all())->toBe([ChannelReconciliationRun::STATUS_DRIFT]);

    Event::assertDispatched(ParityAlertRaised::class);

    // Auto-repush queued under stable per-day keys.
    expect(ChannelMessage::where('idempotency_key', 'like', 'repush.%')->count())->toBeGreaterThan(0);
});
