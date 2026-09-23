<?php

use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\CallRecord;
use App\Models\MobileKey;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\TelecomRate;
use App\Models\Transaction;
use App\Services\AvailabilityService;
use App\Services\BusinessDateService;
use App\Services\MobileKey\FakeMobileKeyVendor;
use App\Services\MobileKeyService;
use App\Services\WifiService;
use Database\Seeders\ChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    FakeMobileKeyVendor::reset();
    $this->branch = Branch::factory()->create(['timezone' => 'Africa/Lagos', 'cdr_secret' => str_repeat('s', 40)]);
    $this->user = $this->makeAdminUser($this->branch);
    $this->seed(ChartSeeder::class);
    $this->today = app(BusinessDateService::class)->current($this->branch)->business_date->toDateString();

    $roomType = RoomType::factory()->create(['branch_id' => $this->branch->id, 'base_rate' => 10000]);
    $this->room = Room::factory()->create([
        'branch_id' => $this->branch->id, 'room_type_id' => $roomType->id,
        'number' => '101', 'status' => 'available', 'is_active' => true,
    ]);

    $this->reservation = app(AvailabilityService::class)->reserve(
        $this->branch, $roomType, $this->today, Carbon::parse($this->today)->addDays(2)->toDateString(),
        [
            'guest_name' => 'Connectivity Guest', 'guest_email' => 'conn@example.com',
            'adults' => 2, 'children' => 0, 'room_rate' => 10000, 'total_amount' => 20000,
            'status' => 'checked_in', 'source' => 'direct', 'payment_status' => 'pending',
        ],
        $this->room->id, (string) Str::uuid(),
    );

    TelecomRate::create(['branch_id' => $this->branch->id, 'destination_prefix' => '+234', 'rate_minor_per_min' => 50, 'is_active' => true]);
});

function cdrCall(TestCase $test, Branch $branch, string $cdrId): TestResponse
{
    $body = json_encode([
        'cdr_id' => $cdrId,
        'extension' => '101',
        'destination' => '+2348012345678',
        'duration_secs' => 150,
        'reservation_id' => $test->reservation->id,
    ]);

    return $test->call(
        'POST', "/api/cdr/{$branch->id}", [], [],
        [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CDR_SIGNATURE' => 'sha256='.hash_hmac('sha256', (string) $body, str_repeat('s', 40))],
        $body
    );
}

it('posts a duplicate CDR once to the folio', function () {
    cdrCall($this, $this->branch, 'cdr-1')->assertCreated();
    cdrCall($this, $this->branch, 'cdr-1')->assertCreated();

    // 150s → 3 minutes × 50 = 150 minor, posted exactly once.
    expect(CallRecord::where('cdr_id', 'cdr-1')->count())->toBe(1)
        ->and(Transaction::where('category', 'telecom')->where('is_voided', false)->count())->toBe(1)
        ->and((int) CallRecord::where('cdr_id', 'cdr-1')->first()?->charge_minor)->toBe(150);
});

it('rejects tampered CDR signatures with 422', function () {
    $body = json_encode(['cdr_id' => 'cdr-x', 'extension' => '101', 'destination' => '+2348', 'duration_secs' => 60]);

    $this->call('POST', "/api/cdr/{$this->branch->id}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_CDR_SIGNATURE' => 'sha256=wrong',
    ], (string) $body)->assertStatus(422);
});

it('rejects wifi vouchers for checked-out stays', function () {
    $this->reservation->update(['status' => 'checked_out']);

    try {
        (new WifiService)->issue($this->branch, $this->reservation->confirmation_number, 'Connectivity Guest');
        $this->fail('Expected a WIFI_AUTH exception.');
    } catch (AvailabilityException $e) {
        expect($e->availabilityCode)->toBe('WIFI_AUTH');
    }
});

it('issues wifi vouchers for in-house guests and validates them', function () {
    $session = (new WifiService)->issue($this->branch, $this->reservation->confirmation_number, 'connectivity guest');

    expect($session->usable())->toBeTrue();

    $checked = (new WifiService)->validate($session->voucher);

    expect($checked->id)->toBe($session->id);

    (new WifiService)->revoke($session);

    try {
        (new WifiService)->validate($session->voucher);
        $this->fail('Expected a WIFI_VOUCHER exception.');
    } catch (AvailabilityException $e) {
        expect($e->availabilityCode)->toBe('WIFI_VOUCHER');
    }
});

it('issues mobile keys idempotently and revokes them on checkout', function () {
    $service = new MobileKeyService;

    $first = $service->issue($this->reservation, 'phone-1');
    $second = $service->issue($this->reservation, 'phone-1');

    expect($first['degraded'])->toBeFalse()
        ->and($second['key']->id)->toBe($first['key']->id)
        ->and(MobileKey::where('reservation_id', $this->reservation->id)->where('status', 'active')->count())->toBe(1)
        ->and(FakeMobileKeyVendor::$provisioned)->toHaveCount(1);

    $this->actingAs($this->user)->post("/reservations/{$this->reservation->id}/check-out")->assertRedirect();

    expect($this->reservation->fresh()?->status)->toBe('checked_out')
        ->and(MobileKey::where('reservation_id', $this->reservation->id)->where('status', 'active')->count())->toBe(0)
        ->and(FakeMobileKeyVendor::$revoked)->toContain($first['key']->id);
});

it('falls back to plastic when the key vendor is down', function () {
    FakeMobileKeyVendor::$down = true;

    $result = (new MobileKeyService)->issue($this->reservation, 'phone-9');

    expect($result['degraded'])->toBeTrue()
        ->and($result['key']->status)->toBe(MobileKey::STATUS_ACTIVE);
});
