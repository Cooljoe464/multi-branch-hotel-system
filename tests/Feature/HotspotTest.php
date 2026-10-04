<?php

use App\Jobs\DeprovisionWifiJob;
use App\Jobs\ProvisionWifiJob;
use App\Models\Branch;
use App\Models\HotspotTier;
use App\Models\ReservationHotspot;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Transaction;
use App\Models\WifiSession;
use App\Services\AvailabilityService;
use App\Services\BusinessDateService;
use App\Services\HotspotService;
use App\Services\MikrotikService;
use App\Services\RadiusService;
use App\Services\RouterOsConfigService;
use App\Services\WifiService;
use Database\Seeders\ChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['timezone' => 'Africa/Lagos']);
    $this->user = $this->makeAdminUser($this->branch);
    $this->seed(ChartSeeder::class);
    $this->today = app(BusinessDateService::class)->current($this->branch)->business_date->toDateString();

    $roomType = RoomType::factory()->create(['branch_id' => $this->branch->id, 'base_rate' => 10000]);
    $this->room = Room::factory()->create([
        'branch_id' => $this->branch->id, 'room_type_id' => $roomType->id,
        'number' => '101', 'status' => 'available', 'is_active' => true,
    ]);

    $this->freeTier = HotspotTier::create([
        'branch_id' => $this->branch->id, 'name' => 'Free Basic', 'code' => 'free',
        'price_minor' => 0, 'rate_up_kbps' => 1024, 'rate_down_kbps' => 2048, 'device_limit' => 2, 'is_active' => true,
    ]);
    $this->paidTier = HotspotTier::create([
        'branch_id' => $this->branch->id, 'name' => 'Premium', 'code' => 'premium',
        'price_minor' => 2500, 'rate_up_kbps' => 10240, 'rate_down_kbps' => 20480, 'device_limit' => 4, 'is_active' => true,
    ]);

    $this->reservation = app(AvailabilityService::class)->reserve(
        $this->branch, $roomType, $this->today, Carbon::parse($this->today)->addDays(2)->toDateString(),
        [
            'guest_name' => 'Hotspot Guest', 'guest_email' => 'hotspot@example.com',
            'adults' => 2, 'children' => 0, 'room_rate' => 10000, 'total_amount' => 20000,
            'status' => 'confirmed', 'source' => 'direct', 'payment_status' => 'pending',
        ],
        $this->room->id, (string) Str::uuid(),
    );
});

it('attaches the free tier by default with no folio charge', function () {
    $row = (new HotspotService)->attachReservation($this->reservation);

    expect($row->hotspot_tier_id)->toBe($this->freeTier->id)
        ->and($row->fee_minor)->toBe(0)
        ->and(Transaction::where('category', 'wifi')->count())->toBe(0);
});

it('posts paid tiers to the folio as wifi charges', function () {
    $row = (new HotspotService)->selectTier($this->reservation, $this->paidTier, $this->user, (string) Str::uuid());

    expect($row->fee_minor)->toBe(2500)
        ->and(Transaction::where('category', 'wifi')->where('is_voided', false)->count())->toBe(1);

    // Re-selecting the same tier is idempotent: no second charge.
    (new HotspotService)->selectTier($this->reservation, $this->paidTier, $this->user);

    expect(Transaction::where('category', 'wifi')->where('is_voided', false)->count())->toBe(1)
        ->and(ReservationHotspot::where('reservation_id', $this->reservation->id)->count())->toBe(1);
});

it('auto-provisions on check-in and deprovisions on check-out', function () {
    Queue::fake();

    $this->actingAs($this->user)->post("/reservations/{$this->reservation->id}/check-in", ['room_id' => $this->room->id])->assertRedirect();

    Queue::assertPushed(ProvisionWifiJob::class);

    // Run the job inline: voucher created, 12-char unambiguous alphabet.
    (new ProvisionWifiJob($this->reservation->id))->handle(app(HotspotService::class), app(RadiusService::class), app(MikrotikService::class));

    $session = WifiSession::where('reservation_id', $this->reservation->id)->first();
    expect($session)->not->toBeNull()
        ->and($session->usable())->toBeTrue()
        ->and(strlen((string) $session->voucher))->toBeGreaterThanOrEqual(12)
        ->and($session->provisioned_at)->not->toBeNull();

    $this->actingAs($this->user)->post("/reservations/{$this->reservation->id}/check-out")->assertRedirect();

    Queue::assertPushed(DeprovisionWifiJob::class);
    expect($this->reservation->fresh()?->status)->toBe('checked_out')
        ->and(WifiSession::where('reservation_id', $this->reservation->id)->whereNull('revoked_at')->count())->toBe(0);
});

it('exports a RouterOS script with hotspot only on the guest bridge', function () {
    $rsc = (new RouterOsConfigService)->export($this->branch);

    expect($rsc)->toContain('/ip hotspot add name=hotspot-b')
        ->and($rsc)->toContain('interface=bridge-guest')
        ->and($rsc)->not->toContain('interface=bridge-staff address-pool=guest-pool')
        ->and($rsc)->toContain('guest->RFC1918 drop')
        ->and($rsc)->toContain('guest->staff drop')
        ->and($rsc)->toContain('/radius add service=hotspot')
        ->and($rsc)->toContain('/interface wireguard');
});

it('rejects portal validation for revoked sessions and wrong branches', function () {
    $other = Branch::factory()->create(['timezone' => 'Africa/Lagos']);

    $this->reservation->update(['status' => 'checked_in']);

    (new ProvisionWifiJob($this->reservation->id))
        ->handle(app(HotspotService::class), app(RadiusService::class), app(MikrotikService::class));

    $session = WifiSession::where('reservation_id', $this->reservation->id)->first();
    expect($session)->not->toBeNull();

    $this->postJson('/api/wifi/validate', ['voucher' => $session->voucher, 'branch_id' => $this->branch->id])->assertOk();
    $this->postJson('/api/wifi/validate', ['voucher' => $session->voucher, 'branch_id' => $other->id])->assertNotFound();

    (new WifiService)->revoke($session);

    $this->postJson('/api/wifi/validate', ['voucher' => $session->voucher])->assertStatus(422);
});
