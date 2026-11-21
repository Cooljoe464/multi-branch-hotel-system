<?php

use App\Models\Branch;
use App\Models\DoorLockAuditLog;
use App\Models\DoorLockGateway;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tenant;
use App\Services\DoorLock\DoorLockService;
use App\Services\DoorLock\FiasLockDriver;
use App\Services\DoorLock\LockSdkProvider;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
    $this->room = Room::factory()->create(['branch_id' => $this->branch->id, 'room_type_id' => $this->roomType->id, 'number' => '302']);
    $this->reservation = Reservation::factory()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $this->room->id,
        'room_type_id' => $this->roomType->id,
    ]);

    app(EntitlementService::class)->flush();
});

it('formats and parses FIAS protocol frames correctly', function () {
    $fias = new FiasLockDriver;

    $frame = $fias->buildFrame('KR', [
        'RN' => '302',
        'G#' => '987',
        'GN' => 'Jane Doe',
        'DA' => '261121',
        'KT' => '1',
    ]);

    expect($frame)->toBe("\x02KR|RN302|G#987|GNJane Doe|DA261121|KT1\x03");

    $parsed = $fias->parseFrame("\x02KA|ASOK|RN302|K#CARD-5544|\x03");
    expect($parsed['record_type'])->toBe('KA')
        ->and($parsed['fields']['AS'])->toBe('OK')
        ->and($parsed['fields']['RN'])->toBe('302')
        ->and($parsed['fields']['K#'])->toBe('CARD-5544');
});

it('issues key via FIAS driver over bridge successfully', function () {
    $gateway = DoorLockGateway::factory()->create([
        'branch_id' => $this->branch->id,
        'provider' => 'fias',
        'api_base_url' => 'http://127.0.0.1:3100',
        'api_key' => 'bridge-test-key',
    ]);

    Http::fake([
        'http://127.0.0.1:3100/fias/send' => Http::response([
            'answer' => base64_encode("\x02KA|ASOK|RN302|K#FIAS-KEY-999|\x03"),
        ], 200),
    ]);

    $log = DoorLockAuditLog::create([
        'branch_id' => $this->branch->id,
        'reservation_id' => $this->reservation->id,
        'room_id' => $this->room->id,
        'gateway_id' => $gateway->id,
        'action' => 'issue_key',
        'valid_from' => now(),
        'valid_until' => now()->addDays(2),
        'status' => 'pending',
    ]);

    $driver = new FiasLockDriver;
    $ok = $driver->issueKey($gateway, $log);

    expect($ok)->toBeTrue()
        ->and($log->fresh()->status)->toBe('success')
        ->and($log->fresh()->credential_id)->toBe('FIAS-KEY-999');
});

it('issues Mifare RFID card via LockSDK provider', function () {
    $gateway = DoorLockGateway::factory()->create([
        'branch_id' => $this->branch->id,
        'provider' => 'lock_sdk',
        'api_base_url' => 'http://127.0.0.1:3100',
        'api_key' => 'bridge-test-key',
    ]);

    Http::fake([
        'http://127.0.0.1:3100/locksdk/execute' => Http::response([
            'status' => 'success',
            'card_sn' => 'MF1-88442211',
        ], 200),
    ]);

    $log = DoorLockAuditLog::create([
        'branch_id' => $this->branch->id,
        'reservation_id' => $this->reservation->id,
        'room_id' => $this->room->id,
        'gateway_id' => $gateway->id,
        'action' => 'issue_key',
        'valid_from' => now(),
        'valid_until' => now()->addDays(2),
        'status' => 'pending',
    ]);

    $driver = new LockSdkProvider;
    $ok = $driver->issueKey($gateway, $log);

    expect($ok)->toBeTrue()
        ->and($log->fresh()->status)->toBe('success')
        ->and($log->fresh()->credential_id)->toBe('MF1-88442211');
});

it('issues keypad passcode and tracks biometric enrollment on smart handles', function () {
    DoorLockGateway::factory()->create([
        'branch_id' => $this->branch->id,
        'provider' => 'ttlock',
        'settings' => ['manual_mode' => true],
    ]);

    $service = new DoorLockService;
    $log = $service->issuePasscode($this->reservation, '123456');

    expect($log->pin_code)->toBe('123456')
        ->and($log->payload['is_biometric_enrolled'])->toBeFalse();

    $enrolled = $service->enrollBiometric($log, [
        'type' => 'fingerprint',
        'template_ref' => 'BIO-TMPL-9988',
    ]);

    expect($enrolled)->toBeTrue()
        ->and($log->fresh()->payload['is_biometric_enrolled'])->toBeTrue()
        ->and($log->fresh()->payload['biometric_type'])->toBe('fingerprint')
        ->and($log->fresh()->payload['biometric_template_ref'])->toBe('BIO-TMPL-9988');
});
