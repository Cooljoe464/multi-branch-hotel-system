<?php

use App\Models\Branch;
use App\Models\RegistrationCard;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('one tenant can own multiple properties', function () {
    $tenant = Tenant::factory()->create();
    $firstProperty = Branch::factory()->for($tenant)->create();
    $secondProperty = Branch::factory()->for($tenant)->create();

    expect($tenant->branches()->orderBy('branches.id')->pluck('branches.id')->all())
        ->toBe([$firstProperty->id, $secondProperty->id]);
});

test('property-owned records inherit their tenant from the property', function () {
    $property = Branch::factory()->create();
    $roomType = RoomType::factory()->for($property)->create();

    expect((int) DB::table('room_types')->where('id', $roomType->id)->value('tenant_id'))
        ->toBe($property->tenant_id);
});

test('property-owned records reject a tenant that does not own the property', function () {
    $property = Branch::factory()->create();
    $roomType = RoomType::factory()->for($property)->create();
    $differentTenant = Tenant::factory()->create();

    expect(fn () => DB::table('room_types')
        ->where('id', $roomType->id)
        ->update(['tenant_id' => $differentTenant->id]))
        ->toThrow(QueryException::class);
});

test('records linked to a tenant-owned record inherit its tenant', function () {
    $property = Branch::factory()->create();
    $reservation = Reservation::factory()->forBranch($property->id)->create();
    $registrationCard = RegistrationCard::factory()->for($reservation)->create();

    expect((int) DB::table('registration_cards')->where('id', $registrationCard->id)->value('tenant_id'))
        ->toBe($property->tenant_id);
});

test('records linked to a tenant-owned record reject another tenant', function () {
    $property = Branch::factory()->create();
    $reservation = Reservation::factory()->forBranch($property->id)->create();
    $differentTenant = Tenant::factory()->create();

    expect(fn () => DB::table('registration_cards')->insert([
        'reservation_id' => $reservation->id,
        'tenant_id' => $differentTenant->id,
        'guest_name' => 'Guest Example',
        'id_type' => 'passport',
        'id_number' => 'TEST-123',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('a new property stays under the current tenant', function () {
    $currentProperty = Branch::factory()->create();
    $user = $this->makeAdminUser($currentProperty);

    $response = $this->actingAs($user)->post(route('admin.branches.store'), [
        'name' => 'New Lagos Property',
        'code' => 'NEW-LOS',
        'city' => 'Lagos',
        'country' => 'NG',
        'timezone' => 'Africa/Lagos',
        'currency_code' => 'NGN',
        'currency_symbol' => '₦',
        'tax_rate' => 7.5,
        'tax_label' => 'VAT',
        'room_types' => [
            [
                'name' => 'Standard Room',
                'code' => 'STD',
                'base_rate' => 2500000,
                'max_occupancy' => 2,
                'bed_count' => 1,
                'bed_type' => 'queen',
            ],
        ],
        'floors' => ['1'],
        'rooms' => [
            [
                'number' => '101',
                'floor' => '1',
                'room_type_code' => 'STD',
            ],
        ],
    ]);

    $response->assertRedirect(route('rooms.index'));

    $newProperty = Branch::query()->where('code', 'NEW-LOS')->firstOrFail();

    expect($newProperty->tenant_id)->toBe($currentProperty->tenant_id);
    $this->assertDatabaseHas('room_types', [
        'branch_id' => $newProperty->id,
        'code' => 'STD',
    ]);
    $this->assertDatabaseHas('rooms', [
        'branch_id' => $newProperty->id,
        'number' => '101',
    ]);
});
