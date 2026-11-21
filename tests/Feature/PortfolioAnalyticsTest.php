<?php

use App\Models\Branch;
use App\Models\DailyLedger;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PortfolioAnalyticsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function portfolioTenant(string $name): Tenant
{
    return Tenant::factory()->create(['name' => $name, 'portfolio_analytics_consent' => true]);
}

function portfolioBranch(Tenant $tenant, int $rooms): Branch
{
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id, 'base_rate' => 10000]);

    Room::factory()->count($rooms)->create([
        'branch_id' => $branch->id,
        'room_type_id' => $roomType->id,
        'status' => 'available',
        'is_active' => true,
    ]);

    return $branch;
}

function portfolioLedger(Branch $branch, int $roomsPosted, int $roomRevenue): void
{
    DailyLedger::create([
        'branch_id' => $branch->id,
        'business_date' => Carbon::now()->toDateString(),
        'status' => 'completed',
        'rooms_posted' => $roomsPosted,
        'total_room_revenue' => $roomRevenue,
        'total_tax' => 0,
        'total_other_charges' => 0,
        'total_payments' => $roomRevenue,
        'net_revenue' => 0,
    ]);
}

test('rolls up branch level kpis into blended tenant totals', function () {
    $tenant = portfolioTenant('Rollup Holdings');
    $branch = portfolioBranch($tenant, 2);

    Reservation::factory()->count(1)->create([
        'branch_id' => $branch->id,
        'status' => 'checked_in',
        'check_in_date' => Carbon::now()->toDateString(),
        'check_out_date' => Carbon::now()->addDays(2)->toDateString(),
    ]);
    portfolioLedger($branch, 1, 5000);

    $admin = User::factory()->create(['is_global_admin' => true]);
    $admin->assignRole('Global Admin');

    $response = $this->actingAs($admin)->getJson('/analytics/portfolio?tenant_id='.$tenant->id);

    $response->assertOk();

    expect($response->json('scope'))->toBe('tenant')
        ->and($response->json('tenant_id'))->toBe($tenant->id)
        ->and($response->json('totals.branches'))->toBe(1)
        ->and($response->json('totals.rooms_sold'))->toBe(1)
        ->and($response->json('totals.total_rooms'))->toBe(2)
        ->and($response->json('totals.occupancy_pct'))->toEqual(50.0)
        ->and($response->json('totals.total_room_revenue'))->toBe(5000)
        ->and($response->json('totals.adr'))->toBe(5000)
        ->and($response->json('totals.revpar'))->toBe(2500);
});

test('aggregates consented tenants into a blended portfolio roll-up', function () {
    $consentedA = portfolioTenant('Consented Group A');
    $branchA = portfolioBranch($consentedA, 2);

    Reservation::factory()->count(1)->create([
        'branch_id' => $branchA->id,
        'status' => 'checked_in',
        'check_in_date' => Carbon::now()->toDateString(),
        'check_out_date' => Carbon::now()->addDays(2)->toDateString(),
    ]);
    portfolioLedger($branchA, 1, 5000);

    $consentedB = portfolioTenant('Consented Group B');
    $branchB = portfolioBranch($consentedB, 2);

    Reservation::factory()->count(2)->create([
        'branch_id' => $branchB->id,
        'status' => 'checked_in',
        'check_in_date' => Carbon::now()->toDateString(),
        'check_out_date' => Carbon::now()->addDays(2)->toDateString(),
    ]);
    portfolioLedger($branchB, 2, 10000);

    $nonConsented = Tenant::factory()->create(['name' => 'Opted Out Group', 'portfolio_analytics_consent' => false]);
    $branchC = portfolioBranch($nonConsented, 2);

    $admin = User::factory()->create(['is_global_admin' => true]);
    $admin->assignRole('Global Admin');

    $summary = app(PortfolioAnalyticsService::class)->portfolioSummary([
        $consentedA->id,
        $consentedB->id,
    ], Carbon::now()->toDateString(), 30);

    expect($summary['totals'])->toEqual([
        'branches' => 2,
        'rooms_sold' => 3,
        'total_rooms' => 4,
        'occupancy_pct' => 75.0,
        'total_room_revenue' => 15000,
        'adr' => 5000,
        'revpar' => 3750,
    ]);

    $response = $this->actingAs($admin)->getJson('/analytics/portfolio');

    $response->assertOk();

    expect($response->json('scope'))->toBe('portfolio')
        ->and($response->json('totals.branches'))->toBe(2)
        ->and($response->json('totals.rooms_sold'))->toBe(3)
        ->and($response->json('totals.total_room_revenue'))->toBe(15000);
});

test('requires cross tenant portfolio consent for tenant scoped roll-ups', function () {
    $ownTenant = portfolioTenant('Own Property');
    $ownBranch = portfolioBranch($ownTenant, 2);

    $targetConsented = Tenant::factory()->create(['name' => 'Other Property', 'portfolio_analytics_consent' => true]);
    $targetBranch = portfolioBranch($targetConsented, 2);

    $targetOptedOut = Tenant::factory()->create(['name' => 'Opted Out Property', 'portfolio_analytics_consent' => false]);

    $propertyOwner = User::factory()->create(['branch_id' => $ownBranch->id]);
    $propertyOwner->assignRole('Property Owner');

    $this->actingAs($propertyOwner)->getJson('/analytics/portfolio?tenant_id='.$targetConsented->id)->assertOk();

    $this->actingAs($propertyOwner)
        ->getJson('/analytics/portfolio?tenant_id='.$targetOptedOut->id)
        ->assertForbidden();
});
