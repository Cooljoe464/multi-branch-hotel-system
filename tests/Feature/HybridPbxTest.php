<?php

use App\Models\Branch;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Pbx\AsteriskAmiPbxDriver;
use App\Services\Pbx\GrandstreamUcmPbxDriver;
use App\Services\Pbx\ThreeCxPbxDriver;
use App\Services\PbxDriverManager;
use App\Services\PbxService;
use App\Services\Security\SecretManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->secrets = app(SecretManager::class);
});

it('resolves configured pbx driver from branch settings', function () {
    $manager = new PbxDriverManager;

    $this->branch->settings = ['pbx_driver' => '3cx'];
    expect($manager->driver($this->branch))->toBeInstanceOf(ThreeCxPbxDriver::class);

    $this->branch->settings = ['pbx_driver' => 'asterisk'];
    expect($manager->driver($this->branch))->toBeInstanceOf(AsteriskAmiPbxDriver::class);

    $this->branch->settings = ['pbx_driver' => 'grandstream'];
    expect($manager->driver($this->branch))->toBeInstanceOf(GrandstreamUcmPbxDriver::class);
});

it('polls 3CX Call Control API and normalizes CDR records', function () {
    $this->secrets->set($this->branch, ThreeCxPbxDriver::SECRET_ENDPOINT, 'https://pbx.example.com');
    $this->secrets->set($this->branch, ThreeCxPbxDriver::SECRET_API_KEY, '3cx-token');

    Http::fake([
        'https://pbx.example.com/api/v1/cdr*' => Http::response([
            'records' => [
                [
                    'call_id' => 'call-9988',
                    'caller' => '204',
                    'callee' => '+2348031234567',
                    'duration' => 125,
                ],
            ],
        ], 200),
    ]);

    $driver = new ThreeCxPbxDriver($this->secrets);
    $records = $driver->poll($this->branch, Carbon::parse('2026-11-21 00:00:00'));

    expect($records)->toHaveCount(1)
        ->and($records[0]['cdr_id'])->toBe('call-9988')
        ->and($records[0]['extension'])->toBe('204')
        ->and($records[0]['duration_secs'])->toBe(125);
});

it('polls Grandstream UCM and normalizes CDR records', function () {
    $this->secrets->set($this->branch, GrandstreamUcmPbxDriver::SECRET_ENDPOINT, 'http://192.168.1.50:8088');
    $this->secrets->set($this->branch, GrandstreamUcmPbxDriver::SECRET_API_KEY, 'gs-token');

    Http::fake([
        'http://192.168.1.50:8088/api/v1/cdrs*' => Http::response([
            'cdrs' => [
                [
                    'acctid' => 'gs-session-12',
                    'caller' => '101',
                    'called' => '08099887766',
                    'talk_time' => 60,
                ],
            ],
        ], 200),
    ]);

    $driver = new GrandstreamUcmPbxDriver($this->secrets);
    $records = $driver->poll($this->branch, Carbon::parse('2026-11-21 00:00:00'));

    expect($records)->toHaveCount(1)
        ->and($records[0]['cdr_id'])->toBe('gs-session-12')
        ->and($records[0]['extension'])->toBe('101');
});

it('updates room housekeeping condition when dialed via room handset', function () {
    $roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
    $room = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $roomType->id,
        'number' => '105',
        'condition' => 'dirty',
    ]);

    $service = new PbxService;

    // Dial *21 from room 105 handset
    $updated = $service->handleHousekeepingDialCode($this->branch, '105', '*21');

    expect($updated)->not->toBeNull()
        ->and($updated->condition)->toBe('clean')
        ->and($room->fresh()->condition)->toBe('clean');

    // Dial *22 for inspected
    $service->handleHousekeepingDialCode($this->branch, '105', '*22');
    expect($room->fresh()->condition)->toBe('inspected');
});
