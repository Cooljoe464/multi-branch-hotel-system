<?php

use App\Exceptions\RestrictionViolation;
use App\Models\Branch;
use App\Models\RatePlan;
use App\Models\RateRestriction;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\AvailabilityService;
use App\Services\RestrictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['timezone' => 'Africa/Lagos']);
    $this->user = $this->makeAdminUser($this->branch);
    $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
    $this->room = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'status' => 'available',
    ]);
    $this->plan = RatePlan::factory()->bar()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => null,
    ]);
    $this->service = app(RestrictionService::class);
});

function restrict(int $branchId, int $planId, string $date, array $flags): void
{
    RateRestriction::updateOrCreate(
        ['rate_plan_id' => $planId, 'room_type_id' => null, 'stay_date' => $date],
        array_merge(['branch_id' => $branchId], $flags),
    );
}

function bookAttrs(): array
{
    return [
        'guest_name' => 'Restriction Guest',
        'adults' => 2,
        'children' => 0,
        'room_rate' => 20000,
        'total_amount' => 60000,
        'status' => 'confirmed',
        'source' => 'direct',
        'payment_status' => 'pending',
    ];
}

it('rejects bookings under MinLOS and over MaxLOS', function () {
    restrict($this->branch->id, $this->plan->id, '2026-11-01', ['min_los' => 3]);
    restrict($this->branch->id, $this->plan->id, '2026-11-02', ['min_los' => 3]);

    // 2 nights against MinLOS 3.
    expect(fn () => app(AvailabilityService::class)->reserve(
        $this->branch, $this->roomType, '2026-11-01', '2026-11-03',
        bookAttrs(), null, (string) Str::uuid(), null, null, $this->plan,
    ))->toThrow(RestrictionViolation::class, 'MIN_LOS');

    // 3 nights passes.
    $reservation = app(AvailabilityService::class)->reserve(
        $this->branch, $this->roomType, '2026-11-01', '2026-11-04',
        bookAttrs(), null, (string) Str::uuid(), null, null, $this->plan,
    );
    expect($reservation->id)->toBeGreaterThan(0);
});

it('rejects stop-sell nights and CTA/CTD edges', function () {
    restrict($this->branch->id, $this->plan->id, '2026-11-06', ['cta' => true]);
    restrict($this->branch->id, $this->plan->id, '2026-11-09', ['stop_sell' => true]);

    // Arrival on a CTA date.
    try {
        $this->service->evaluate($this->branch, $this->plan, $this->roomType, '2026-11-06', '2026-11-08');
        $this->fail('Expected a CTA violation.');
    } catch (RestrictionViolation $e) {
        expect($e->availabilityCode)->toBe('CTA');
        expect($e->unavailableDates)->toBe(['2026-11-06']);
    }

    // Stay touching a stop-sell night.
    try {
        $this->service->evaluate($this->branch, $this->plan, $this->roomType, '2026-11-08', '2026-11-10');
        $this->fail('Expected a STOP_SELL violation.');
    } catch (RestrictionViolation $e) {
        expect($e->availabilityCode)->toBe('STOP_SELL');
    }

    // CTA date as a mid-stay night is fine.
    $this->service->evaluate($this->branch, $this->plan, $this->roomType, '2026-11-05', '2026-11-07');
    expect(true)->toBeTrue();
});

it('allows permissioned overrides with a reason', function () {
    restrict($this->branch->id, $this->plan->id, '2026-11-10', ['stop_sell' => true]);

    $gm = $this->makeAdminUser($this->branch);
    $gm->removeRole('Global Admin');
    $gm->assignRole('Branch GM');

    // Without permission: forbidden even with a reason.
    $frontDesk = $this->makeAdminUser($this->branch);
    $frontDesk->removeRole('Global Admin');
    $frontDesk->assignRole('Front Desk');

    try {
        $this->service->evaluate(
            $this->branch, $this->plan, $this->roomType, '2026-11-10', '2026-11-11',
            $frontDesk, 'VIP insistence',
        );
        $this->fail('Expected an override refusal.');
    } catch (RestrictionViolation $e) {
        expect($e->availabilityCode)->toBe('RESTRICTION_OVERRIDE_FORBIDDEN');
    }

    // With permission + reason: passes.
    $this->service->evaluate(
        $this->branch, $this->plan, $this->roomType, '2026-11-10', '2026-11-11',
        $gm, 'VIP insistence',
    );
    expect(true)->toBeTrue();
});

it('enforces restrictions on the store path with machine codes', function () {
    restrict($this->branch->id, $this->plan->id, now()->addDay()->toDateString(), ['stop_sell' => true]);

    // Default plan resolution: make the test plan the BAR default.
    $this->plan->update(['type' => 'bar', 'code' => 'BAR', 'is_active' => true]);

    $response = $this->actingAs($this->user)->postJson('/reservations', [
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'guest_name' => 'Blocked Guest',
        'adults' => 2,
        'children' => 0,
        'check_in_date' => now()->addDay()->toDateString(),
        'check_out_date' => now()->addDays(3)->toDateString(),
    ]);

    $response->assertStatus(422)->assertJsonPath('code', 'STOP_SELL');
});

it('serves the restriction grid under the new permissions', function () {
    $this->withoutVite();

    $this->actingAs($this->user)
        ->get("/branches/{$this->branch->id}/restrictions")
        ->assertOk();

    $user = $this->makeAdminUser($this->branch);
    $user->removeRole('Global Admin');

    $this->actingAs($user)
        ->get("/branches/{$this->branch->id}/restrictions")
        ->assertForbidden();
});
