<?php

use App\Models\Branch;
use App\Models\Folio;
use App\Models\OverbookingAllowance;
use App\Models\PublicApiQuote;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Supplier;
use App\Models\SupplierInputVatInvoice;
use App\Models\Task;
use App\Models\TaxRuleVersion;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PublicApiKeyService;
use App\Support\TenantDatabaseContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function withRlsRuntimeRole(callable $callback): mixed
{
    $role = DB::selectOne(<<<'SQL'
        SELECT rolname
        FROM pg_roles
        WHERE NOT rolsuper
            AND NOT rolbypassrls
            AND has_table_privilege(rolname, 'public.public_api_quotes', 'SELECT')
            AND has_table_privilege(rolname, 'public.overbooking_allowances', 'SELECT')
            AND has_table_privilege(rolname, 'public.supplier_input_vat_invoices', 'SELECT')
        ORDER BY CASE WHEN rolname = 'hotel_app' THEN 0 ELSE 1 END, rolname
        LIMIT 1
        SQL);

    if (! is_object($role) || ! is_string($role->rolname ?? null)) {
        test()->markTestSkipped('No non-BYPASSRLS runtime role has access to the tenant-scoped tables.');
    }

    $roleName = str_replace('"', '""', $role->rolname);
    DB::statement('SET LOCAL ROLE "'.$roleName.'"');

    try {
        return $callback();
    } finally {
        DB::statement('RESET ROLE');
    }
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->branchA = Branch::factory()->create();
    $this->branchB = Branch::factory()->create();

    $this->gmA = User::factory()->create(['branch_id' => $this->branchA->id]);
    $this->gmA->assignRole('Branch GM');
    $this->gmA->branches()->syncWithoutDetaching([$this->branchA->id]);

    $this->frontDeskA = User::factory()->create(['branch_id' => $this->branchA->id]);
    $this->frontDeskA->assignRole('Front Desk');
    $this->frontDeskA->branches()->syncWithoutDetaching([$this->branchA->id]);
});

test('AC-033 branch gm from branch a cannot view reservation from branch b', function () {
    $reservationB = Reservation::factory()->confirmed()->create([
        'branch_id' => $this->branchB->id,
    ]);

    $this->actingAs($this->gmA)
        ->get("/reservations/{$reservationB->id}")
        ->assertForbidden();
});

test('front desk from branch a cannot view reservation from branch b', function () {
    $reservationB = Reservation::factory()->confirmed()->create([
        'branch_id' => $this->branchB->id,
    ]);

    $this->actingAs($this->frontDeskA)
        ->get("/reservations/{$reservationB->id}")
        ->assertForbidden();
});

test('branch gm from branch a cannot update reservation from branch b', function () {
    $reservationB = Reservation::factory()->confirmed()->create([
        'branch_id' => $this->branchB->id,
    ]);

    $this->actingAs($this->gmA)
        ->put("/reservations/{$reservationB->id}", [
            'guest_name' => 'Hacked Name',
        ])
        ->assertForbidden();

    $this->assertDatabaseMissing('reservations', [
        'id' => $reservationB->id,
        'guest_name' => 'Hacked Name',
    ]);
});

test('branch gm from branch a cannot cancel reservation from branch b', function () {
    $reservationB = Reservation::factory()->confirmed()->create([
        'branch_id' => $this->branchB->id,
    ]);

    $this->actingAs($this->gmA)
        ->post("/reservations/{$reservationB->id}/cancel")
        ->assertForbidden();

    $this->assertDatabaseHas('reservations', [
        'id' => $reservationB->id,
        'status' => 'confirmed',
    ]);
});

test('branch gm from branch a cannot delete reservation from branch b', function () {
    $reservationB = Reservation::factory()->confirmed()->create([
        'branch_id' => $this->branchB->id,
    ]);

    $this->actingAs($this->gmA)
        ->delete("/reservations/{$reservationB->id}")
        ->assertForbidden();

    $this->assertDatabaseHas('reservations', [
        'id' => $reservationB->id,
    ]);
});

test('branch gm from branch a cannot check in reservation from branch b', function () {
    $roomB = Room::factory()->create([
        'branch_id' => $this->branchB->id,
        'status' => 'available',
    ]);
    $reservationB = Reservation::factory()->confirmed()->create([
        'branch_id' => $this->branchB->id,
        'room_type_id' => $roomB->room_type_id,
    ]);

    $this->actingAs($this->gmA)
        ->post("/reservations/{$reservationB->id}/check-in", [
            'room_id' => $roomB->id,
        ])
        ->assertForbidden();
});

test('branch gm from branch a cannot update room status from branch b', function () {
    $roomB = Room::factory()->create([
        'branch_id' => $this->branchB->id,
    ]);

    $this->actingAs($this->gmA)
        ->patch("/rooms/{$roomB->id}/status", ['status' => 'dirty'])
        ->assertForbidden();
});

test('branch gm from branch a cannot view folio from branch b', function () {
    $folioB = Folio::create([
        'branch_id' => $this->branchB->id,
        'folio_number' => Folio::generateFolioNumber(),
        'type' => 'master',
        'status' => 'open',
    ]);

    $this->actingAs($this->gmA)
        ->get("/folios/{$folioB->id}")
        ->assertForbidden();
});

test('branch gm from branch a cannot create child folio under branch b master', function () {
    $masterB = Folio::create([
        'branch_id' => $this->branchB->id,
        'folio_number' => Folio::generateFolioNumber(),
        'type' => 'master',
        'status' => 'open',
    ]);

    $this->actingAs($this->gmA)
        ->post("/folios/{$masterB->id}/child", [
            'description' => 'Cross-branch child',
        ])
        ->assertForbidden();
});

test('branch gm from branch a cannot transfer transaction from branch b folio', function () {
    $folioB = Folio::create([
        'branch_id' => $this->branchB->id,
        'folio_number' => Folio::generateFolioNumber(),
        'type' => 'master',
        'status' => 'open',
    ]);
    $transaction = Transaction::factory()->debit()->create(['folio_id' => $folioB->id]);

    $folioA = Folio::create([
        'branch_id' => $this->branchA->id,
        'folio_number' => Folio::generateFolioNumber(),
        'type' => 'master',
        'status' => 'open',
    ]);

    $this->actingAs($this->gmA)
        ->post("/folios/transactions/{$transaction->id}/transfer", [
            'target_folio_id' => $folioA->id,
        ])
        ->assertForbidden();
});

test('branch gm from branch a cannot post maintenance ticket for branch b', function () {
    $roomB = Room::factory()->create(['branch_id' => $this->branchB->id]);

    $this->actingAs($this->gmA)
        ->post('/maintenance', [
            'room_id' => $roomB->id,
            'category' => 'plumbing',
            'priority' => 'normal',
            'title' => 'Cross-branch leak',
            'description' => 'Attempting unauthorized access.',
        ])
        ->assertForbidden();
});

test('branch gm from branch a cannot complete housekeeping task from branch b', function () {
    $taskB = Task::factory()->pending()->create([
        'branch_id' => $this->branchB->id,
        'assigned_to' => $this->gmA->id,
    ]);

    $this->actingAs($this->gmA)
        ->post("/housekeeping/{$taskB->id}/complete")
        ->assertForbidden();
});

test('pos endpoint blocks cross-branch charge posting', function () {
    $reservationB = Reservation::factory()->checkedIn()->create([
        'branch_id' => $this->branchB->id,
    ]);

    $token = $this->gmA->createToken('pos-token');

    $response = $this->withToken($token->plainTextToken)
        ->postJson('/api/pos/charge', [
            'reservation_id' => $reservationB->id,
            'outlet' => 'restaurant',
            'items' => [['name' => 'Pizza', 'qty' => 1, 'unit_price' => 1500]],
        ]);

    $response->assertStatus(403);
});

test('branch gm can access all resources within own branch', function () {
    $roomA = Room::factory()->create([
        'branch_id' => $this->branchA->id,
        'status' => 'available',
    ]);
    $reservationA = Reservation::factory()->confirmed()->create([
        'branch_id' => $this->branchA->id,
        'room_type_id' => $roomA->room_type_id,
    ]);
    $folioA = Folio::create([
        'branch_id' => $this->branchA->id,
        'folio_number' => Folio::generateFolioNumber(),
        'type' => 'master',
        'status' => 'open',
    ]);

    $this->actingAs($this->gmA)->get('/rooms')->assertOk();
    $this->actingAs($this->gmA)->get('/reservations')->assertOk();
    $this->actingAs($this->gmA)->get("/reservations/{$reservationA->id}")->assertOk();
    $this->actingAs($this->gmA)->get('/folios')->assertOk();
    $this->actingAs($this->gmA)->get("/folios/{$folioA->id}")->assertOk();
});

test('global admin can access resources across all branches', function () {
    $admin = User::factory()->create([
        'branch_id' => $this->branchA->id,
        'is_global_admin' => true,
    ]);
    $admin->assignRole('Global Admin');
    $admin->branches()->syncWithoutDetaching([$this->branchA->id]);

    $reservationB = Reservation::factory()->confirmed()->create([
        'branch_id' => $this->branchB->id,
    ]);

    $this->actingAs($admin)->get("/reservations/{$reservationB->id}")->assertOk();
});

test('branch gm from branch a cannot view analytics for branch b', function () {
    $this->actingAs($this->gmA)->get('/analytics')->assertOk();
    $this->actingAs($this->gmA)->get('/reports')->assertOk();
});

test('front desk cannot access cross-branch search', function () {
    $query = '?check_in='.now()->addDay()->toDateString()
        .'&check_out='.now()->addDays(3)->toDateString()
        .'&adults=2';

    $this->actingAs($this->frontDeskA)
        ->get("/reservations/cross-branch/search{$query}")
        ->assertForbidden();
});

test('AC-033 public API quotes stay isolated between tenant database contexts', function () {
    $roomType = RoomType::factory()->create(['branch_id' => $this->branchB->id]);
    $ratePlan = RatePlan::factory()->forBranch($this->branchB->id)->create([
        'valid_from' => now()->subYear()->toDateString(),
        'valid_to' => null,
    ]);
    $apiKey = app(PublicApiKeyService::class)->issue($this->branchB, ['scopes' => ['availability.view']])['key'];
    $quote = PublicApiQuote::factory()->forBooking($apiKey, $roomType, $ratePlan)->create();
    $tenantContext = app(TenantDatabaseContext::class);

    $visibleToTenantA = $tenantContext->run(
        null,
        $this->branchA->tenant_id,
        fn (): bool => withRlsRuntimeRole(fn (): bool => PublicApiQuote::query()->whereKey($quote->id)->exists()),
        actorType: 'test',
    );
    $visibleToTenantB = $tenantContext->run(
        null,
        $this->branchB->tenant_id,
        fn (): bool => PublicApiQuote::query()->whereKey($quote->id)->exists(),
        actorType: 'test',
    );

    expect($visibleToTenantA)->toBeFalse()
        ->and($visibleToTenantB)->toBeTrue();
});

test('AC-033 overbooking allowances remain tenant isolated under pooled database contexts', function () {
    $roomType = RoomType::factory()->create(['branch_id' => $this->branchB->id]);
    $creator = User::factory()->create(['branch_id' => $this->branchB->id]);
    $allowance = OverbookingAllowance::factory()->create([
        'tenant_id' => $this->branchB->tenant_id,
        'branch_id' => $this->branchB->id,
        'room_type_id' => $roomType->id,
        'created_by' => $creator->id,
    ]);
    $tenantContext = app(TenantDatabaseContext::class);

    $visibleToTenantA = $tenantContext->run(
        null,
        $this->branchA->tenant_id,
        fn (): bool => withRlsRuntimeRole(fn (): bool => OverbookingAllowance::query()->whereKey($allowance->id)->exists()),
        actorType: 'test',
    );
    $visibleToTenantB = $tenantContext->run(
        null,
        $this->branchB->tenant_id,
        fn (): bool => OverbookingAllowance::query()->whereKey($allowance->id)->exists(),
        actorType: 'test',
    );

    expect($visibleToTenantA)->toBeFalse()
        ->and($visibleToTenantB)->toBeTrue();
});

test('AC-033 effective tax rules remain isolated between tenant database contexts', function () {
    $rule = TaxRuleVersion::query()->create([
        'tenant_id' => $this->branchB->tenant_id,
        'branch_id' => $this->branchB->id,
        'rule_key' => 'tax_component.VAT.rate_bps',
        'value' => ['rate_bps' => 750],
        'effective_from' => now()->toDateString(),
        'evidence_snapshot' => ['source' => 'fixture'],
        'approval_reference' => 'TEST-TAX-APPROVAL',
    ]);
    $tenantContext = app(TenantDatabaseContext::class);

    $visibleToTenantA = $tenantContext->run(
        null,
        $this->branchA->tenant_id,
        fn (): bool => withRlsRuntimeRole(fn (): bool => TaxRuleVersion::query()->whereKey($rule->id)->exists()),
        actorType: 'test',
    );
    $visibleToTenantB = $tenantContext->run(
        null,
        $this->branchB->tenant_id,
        fn (): bool => TaxRuleVersion::query()->whereKey($rule->id)->exists(),
        actorType: 'test',
    );

    expect($visibleToTenantA)->toBeFalse()
        ->and($visibleToTenantB)->toBeTrue();
});

test('AC-033 supplier input VAT invoices remain isolated between tenant database contexts', function () {
    $supplier = Supplier::query()->create([
        'branch_id' => $this->branchB->id,
        'name' => 'Tenant B Supplier',
    ]);
    $invoice = SupplierInputVatInvoice::factory()->create([
        'tenant_id' => $this->branchB->tenant_id,
        'branch_id' => $this->branchB->id,
        'supplier_id' => $supplier->id,
    ]);
    $tenantContext = app(TenantDatabaseContext::class);

    $visibleToTenantA = $tenantContext->run(
        null,
        $this->branchA->tenant_id,
        fn (): bool => withRlsRuntimeRole(fn (): bool => SupplierInputVatInvoice::query()->whereKey($invoice->id)->exists()),
        actorType: 'test',
    );
    $visibleToTenantB = $tenantContext->run(
        null,
        $this->branchB->tenant_id,
        fn (): bool => SupplierInputVatInvoice::query()->whereKey($invoice->id)->exists(),
        actorType: 'test',
    );

    expect($visibleToTenantA)->toBeFalse()
        ->and($visibleToTenantB)->toBeTrue();
});
