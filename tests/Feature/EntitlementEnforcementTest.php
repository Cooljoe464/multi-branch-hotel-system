<?php

use App\Models\Branch;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

uses(RefreshDatabase::class);

/**
 * A tenant, its property and an admin, subscribed to a plan granting $features.
 *
 * @param  list<string>  $features
 * @return array{tenant: Tenant, branch: Branch, user: User, subscription: Subscription}
 */
function subscribedProperty(TestCase $test, array $features, string $status = Subscription::STATUS_ACTIVE): array
{
    $tenant = Tenant::factory()->create();
    $branch = Branch::factory()->for($tenant)->create();
    $test->seed(RoleSeeder::class);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $user->assignRole('Global Admin');
    $user->branches()->syncWithoutDetaching([$branch->id]);
    $plan = Plan::factory()->create();

    foreach ($features as $feature) {
        PlanEntitlement::create([
            'plan_id' => $plan->id,
            'entitlement_key' => $feature,
            'value_type' => PlanEntitlement::TYPE_BOOLEAN,
            'value' => 'true',
        ]);
    }

    $subscription = Subscription::factory()->for($tenant)->for($plan)->create(['status' => $status]);

    return compact('tenant', 'branch', 'user', 'subscription');
}

test('forbids creating a reservation when the plan lacks pms.reservations', function () {
    ['user' => $user] = subscribedProperty($this, ['finance.folios']);

    $this->actingAs($user)->post('/reservations', [])->assertForbidden();
    $this->actingAs($user)->get('/reservations/create')->assertForbidden();
});

test('names the missing entitlement in the JSON refusal', function () {
    ['user' => $user] = subscribedProperty($this, ['finance.folios']);

    $this->actingAs($user)
        ->postJson('/reservations', [])
        ->assertForbidden()
        ->assertJson(['entitlement' => 'pms.reservations']);
});

test('lets an entitled tenant reach reservation validation', function () {
    ['user' => $user] = subscribedProperty($this, ['pms.reservations']);

    $this->actingAs($user)->post('/reservations', [])->assertSessionHasErrors();
});

test('forbids the POS terminal when the plan lacks pos.sales', function () {
    ['user' => $user] = subscribedProperty($this, ['pms.reservations']);

    $this->actingAs($user)->get('/pos')->assertForbidden();
});

test('lets a tenant with no subscription use every module while enforcement is off', function () {
    $branch = Branch::factory()->create();
    $user = $this->makeAdminUser($branch);

    $this->actingAs($user)->post('/reservations', [])->assertSessionHasErrors();
});

test('denies a tenant with no subscription once enforcement is on', function () {
    config(['entitlements.enforce_without_subscription' => true]);
    $branch = Branch::factory()->create();
    $user = $this->makeAdminUser($branch);

    $this->actingAs($user)->post('/reservations', [])->assertForbidden();
});

test('keeps checkout, folios and the reservation list working for a restricted subscription with a guest in house', function () {
    ['branch' => $branch, 'user' => $user] = subscribedProperty(
        $this,
        ['pms.reservations', 'pos.sales', 'finance.folios', 'pms.front_desk'],
        Subscription::STATUS_RESTRICTED,
    );
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    $room = Room::factory()->create(['branch_id' => $branch->id, 'room_type_id' => $roomType->id]);
    $reservation = Reservation::factory()->checkedIn()->create([
        'branch_id' => $branch->id,
        'room_type_id' => $roomType->id,
        'room_id' => $room->id,
    ]);

    $this->actingAs($user)->get('/folios')->assertOk();
    $this->actingAs($user)->get('/reservations')->assertOk();
    $this->actingAs($user)->post("/reservations/{$reservation->id}/check-out")->assertRedirect();

    expect($reservation->fresh()->status)->toBe('checked_out');
});

test('blocks new reservations and premium modules for a restricted subscription', function () {
    ['user' => $user] = subscribedProperty(
        $this,
        ['pms.reservations', 'pos.sales', 'finance.folios'],
        Subscription::STATUS_RESTRICTED,
    );

    $this->actingAs($user)->post('/reservations', [])->assertForbidden();
    $this->actingAs($user)->get('/pos')->assertForbidden();
});

test('shares the resolved entitlements with the frontend', function () {
    ['user' => $user] = subscribedProperty($this, ['pms.reservations']);

    $this->actingAs($user)
        ->get('/reservations')
        ->assertInertia(fn (AssertableInertia $page) => $page->where(
            'entitlements',
            fn ($entitlements) => ($entitlements['pms.reservations'] ?? null) === true
                && ! isset($entitlements['pos.sales']),
        ));
});

test('shares no entitlement map for a tenant that is not yet metered', function () {
    $branch = Branch::factory()->create();
    $user = $this->makeAdminUser($branch);

    $this->actingAs($user)
        ->get('/reservations')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('entitlements', null));
});
