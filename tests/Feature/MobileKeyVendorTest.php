<?php

use App\Models\Branch;
use App\Models\MobileKey;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\MobileKey\AssaAbloyMobileAccessVendor;
use App\Services\MobileKey\SaltoJustInVendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
    $this->room = Room::factory()->create(['branch_id' => $this->branch->id, 'room_type_id' => $this->roomType->id, 'number' => '401']);
    $this->reservation = Reservation::factory()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $this->room->id,
        'room_type_id' => $this->roomType->id,
        'status' => 'confirmed',
    ]);
});

it('provisions digital key through Assa Abloy Mobile Access driver', function () {
    Http::fake([
        'https://api.assaabloyhospitality.com/mobile-access/v1/keys/issue' => Http::response([
            'credential_id' => 'assa-cred-7788',
            'status' => 'active',
        ], 200),
    ]);

    $key = MobileKey::create([
        'reservation_id' => $this->reservation->id,
        'device_id' => 'iphone-uuid-1234',
        'key_enc' => Crypt::encryptString(Str::random(48)),
        'valid_from' => now(),
        'valid_to' => now()->addDays(2),
        'status' => MobileKey::STATUS_ACTIVE,
    ]);

    $vendor = new AssaAbloyMobileAccessVendor;
    $credId = $vendor->provision($key);

    expect($credId)->toBe('assa-cred-7788');
});

it('provisions digital key through Salto JustIN driver', function () {
    Http::fake([
        'https://api.saltosystems.com/justin/v1/keys' => Http::response([
            'key_id' => 'salto-key-9900',
            'status' => 'delivered',
        ], 200),
    ]);

    $key = MobileKey::create([
        'reservation_id' => $this->reservation->id,
        'device_id' => 'android-uuid-5678',
        'key_enc' => Crypt::encryptString(Str::random(48)),
        'valid_from' => now(),
        'valid_to' => now()->addDays(3),
        'status' => MobileKey::STATUS_ACTIVE,
    ]);

    $vendor = new SaltoJustInVendor;
    $credId = $vendor->provision($key);

    expect($credId)->toBe('salto-key-9900');
});
