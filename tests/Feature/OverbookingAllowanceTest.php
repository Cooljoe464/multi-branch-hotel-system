<?php

use App\Models\Branch;
use App\Models\OverbookingAllowance;
use App\Models\RoomType;
use App\Services\OverbookingAllowanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('AC-034 requires confirmed MFA and records a scoped, audited allowance', function () {
    $branch = Branch::factory()->create();
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    $user = $this->makeAdminUser($branch);
    $startsOn = now()->addDays(4)->toDateString();
    $endsOn = now()->addDays(6)->toDateString();
    $payload = [
        'room_type_id' => $roomType->id,
        'starts_on' => $startsOn,
        'ends_on' => $endsOn,
        'allowed_count' => 2,
        'allowed_sources' => ['front_desk'],
        'reason' => 'Approved event arrival overflow.',
    ];

    $this->actingAs($user)
        ->postJson("/branches/{$branch->id}/overbooking-allowances", $payload)
        ->assertForbidden();

    $user->forceFill(['two_factor_secret' => 'confirmed-secret', 'two_factor_confirmed_at' => now()])->save();

    $this->actingAs($user)
        ->post("/branches/{$branch->id}/overbooking-allowances", $payload)
        ->assertRedirect();

    $allowance = OverbookingAllowance::query()->where('branch_id', $branch->id)->firstOrFail();
    expect($allowance->created_by)->toBe($user->id)
        ->and($allowance->allowed_sources)->toBe(['front_desk'])
        ->and($allowance->starts_on->toDateString())->toBe($startsOn);

    expect(fn () => app(OverbookingAllowanceService::class)->create(
        $branch,
        $roomType,
        now()->addDays(5)->toDateString(),
        now()->addDays(7)->toDateString(),
        1,
        ['ota'],
        'Overlapping allowance.',
        $user,
    ))->toThrow('An active allowance already covers');
});

it('AC-034 rejects an overbooking allowance that names a room type at another property', function () {
    $branch = Branch::factory()->create();
    $otherBranch = Branch::factory()->create(['tenant_id' => $branch->tenant_id]);
    $roomType = RoomType::factory()->create(['branch_id' => $otherBranch->id]);
    $user = $this->makeAdminUser($branch);
    $user->forceFill(['two_factor_secret' => 'confirmed-secret', 'two_factor_confirmed_at' => now()])->save();

    expect(fn () => app(OverbookingAllowanceService::class)->create(
        $branch,
        $roomType,
        now()->addDay()->toDateString(),
        now()->addDays(2)->toDateString(),
        1,
        ['front_desk'],
        'Wrong property.',
        $user,
    ))->toThrow('The room type is not active at this property.');
});

it('lists allowances on the management page and warns when MFA is missing', function () {
    $this->withoutVite();
    $branch = Branch::factory()->create();
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    $user = $this->makeAdminUser($branch);
    OverbookingAllowance::factory()->forRoomType($branch, $roomType)->create();

    $this->actingAs($user)
        ->get("/branches/{$branch->id}/overbooking-allowances")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('rooms/OverbookingAllowances')
            ->has('allowances', 1)
            ->has('roomTypes', 1)
            ->where('twoFactorEnabled', false));
});

it('does not show another property allowances', function () {
    $this->withoutVite();
    $branch = Branch::factory()->create();
    $other = Branch::factory()->create();
    $user = $this->makeAdminUser($branch);
    $user->forceFill(['is_global_admin' => false])->save();

    $this->actingAs($user)
        ->get("/branches/{$other->id}/overbooking-allowances")
        ->assertForbidden();
});
