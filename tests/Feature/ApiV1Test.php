<?php

use App\Models\ApiConsumer;
use App\Models\Branch;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use Database\Seeders\ChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['timezone' => 'Africa/Lagos']);
    $this->seed(ChartSeeder::class);
    $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id, 'base_rate' => 10000]);
    foreach (['101', '102'] as $number) {
        Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'number' => $number,
            'status' => 'available',
            'is_active' => true,
        ]);
    }
    $this->checkIn = Carbon::today()->addDays(2)->toDateString();
    $this->checkOut = Carbon::today()->addDays(4)->toDateString();
});

function apiConsumer(Branch $branch, array $scopes, ?array $branchIds = null): ApiConsumer
{
    return ApiConsumer::create([
        'name' => 'Test Consumer',
        'branch_id' => $branch->id,
        'branch_ids' => $branchIds,
        'scopes' => $scopes,
        'is_active' => true,
    ]);
}

function bearer(ApiConsumer $consumer, ?Carbon $expiresAt = null): string
{
    return $consumer->createToken('test', $consumer->tokenAbilities(), $expiresAt)->plainTextToken;
}

function reservationPayload(RoomType $roomType, string $checkIn, string $checkOut): array
{
    return [
        'room_type_id' => $roomType->id,
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'guest_name' => 'API Guest',
        'guest_email' => 'api@example.com',
        'adults' => 2,
    ];
}

it('quotes availability for the token property', function () {
    $consumer = apiConsumer($this->branch, ['availability.view']);

    $response = $this->withToken(bearer($consumer))->getJson('/api/v1/availability?'.http_build_query([
        'room_type_id' => $this->roomType->id,
        'check_in' => $this->checkIn,
        'check_out' => $this->checkOut,
    ]));

    $response->assertOk()
        ->assertJsonPath('data.branch_id', $this->branch->id)
        ->assertJsonPath('data.currency_code', $this->branch->currency_code);
});

it('forbids writes with an out-of-scope token', function () {
    $consumer = apiConsumer($this->branch, ['availability.view']);

    $this->withToken(bearer($consumer))
        ->postJson('/api/v1/reservations', reservationPayload($this->roomType, $this->checkIn, $this->checkOut), ['X-Idempotency-Key' => (string) Str::uuid()])
        ->assertForbidden();
});

it('rejects expired tokens with 401', function () {
    $consumer = apiConsumer($this->branch, ['availability.view']);

    $this->withToken(bearer($consumer, Carbon::now()->subMinute()))
        ->getJson('/api/v1/availability?'.http_build_query([
            'room_type_id' => $this->roomType->id,
            'check_in' => $this->checkIn,
            'check_out' => $this->checkOut,
        ]))
        ->assertUnauthorized();
});

it('rejects writes without an idempotency key with 422', function () {
    $consumer = apiConsumer($this->branch, ['reservations.create']);

    $this->withToken(bearer($consumer))
        ->postJson('/api/v1/reservations', reservationPayload($this->roomType, $this->checkIn, $this->checkOut), ['X-Idempotency-Key' => ''])
        ->assertStatus(422)
        ->assertSee('X-Idempotency-Key');
});

it('creates a reservation once and replays the stored reply', function () {
    $consumer = apiConsumer($this->branch, ['reservations.create', 'reservations.view']);
    $token = bearer($consumer);
    $key = (string) Str::uuid();

    $first = $this->withToken($token)->postJson('/api/v1/reservations', reservationPayload($this->roomType, $this->checkIn, $this->checkOut), ['X-Idempotency-Key' => $key]);
    $first->assertCreated()->assertJsonPath('data.status', 'confirmed');

    $confirmation = $first->json('data.confirmation_number');
    expect($confirmation)->not->toBeEmpty();

    $second = $this->withToken($token)->postJson('/api/v1/reservations', reservationPayload($this->roomType, $this->checkIn, $this->checkOut), ['X-Idempotency-Key' => $key]);
    $second->assertCreated()->assertJsonPath('data.confirmation_number', $confirmation);

    expect(Reservation::forBranch($this->branch->id)->count())->toBe(1);
});

it('hides sister-branch reservations with 404', function () {
    $other = Branch::factory()->create(['timezone' => 'Africa/Lagos']);
    $stray = Reservation::factory()->create(['branch_id' => $other->id]);

    $consumer = apiConsumer($this->branch, ['reservations.view']);

    $this->withToken(bearer($consumer))->getJson("/api/v1/reservations/{$stray->id}")->assertNotFound();
});

it('cancels a reservation and reports the penalty', function () {
    $consumer = apiConsumer($this->branch, ['reservations.create', 'reservations.cancel']);
    $token = bearer($consumer);

    $created = $this->withToken($token)->postJson('/api/v1/reservations', reservationPayload($this->roomType, $this->checkIn, $this->checkOut), ['X-Idempotency-Key' => (string) Str::uuid()]);
    $created->assertCreated();
    $id = $created->json('data.id');

    $cancelled = $this->withToken($token)->postJson("/api/v1/reservations/{$id}/cancel", [], ['X-Idempotency-Key' => (string) Str::uuid()]);
    $cancelled->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonStructure(['penalty_minor']);
});

it('requires X-Branch-Id for multi-property tokens', function () {
    $second = Branch::factory()->create(['timezone' => 'Africa/Lagos']);
    $consumer = ApiConsumer::create([
        'name' => 'Global Consumer',
        'branch_id' => null,
        'branch_ids' => [$this->branch->id, $second->id],
        'scopes' => ['availability.view'],
        'is_active' => true,
    ]);

    $query = http_build_query([
        'room_type_id' => $this->roomType->id,
        'check_in' => $this->checkIn,
        'check_out' => $this->checkOut,
    ]);

    $this->withToken(bearer($consumer))->getJson("/api/v1/availability?{$query}")->assertStatus(422);

    $this->withToken(bearer($consumer), 'Bearer')
        ->getJson("/api/v1/availability?{$query}", ['X-Branch-Id' => (string) $second->id])
        ->assertStatus(404);
});
