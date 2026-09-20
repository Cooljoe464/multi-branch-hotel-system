<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\GroupLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
        $this->user = $this->makeAdminUser($this->branch);
    }

    public function test_can_list_group_ledgers(): void
    {
        GroupLedger::factory()->forBranch($this->branch->id)->create();

        $response = $this->actingAs($this->user)->get('/group-ledgers');

        $response->assertStatus(200);
    }

    public function test_can_show_group_ledger(): void
    {
        $ledger = GroupLedger::factory()->forBranch($this->branch->id)->create();

        $response = $this->actingAs($this->user)->get("/group-ledgers/{$ledger->id}");

        $response->assertStatus(200);
    }

    public function test_can_create_group_ledger(): void
    {
        $response = $this->actingAs($this->user)->post('/group-ledgers', [
            'business_date' => '2026-09-14',
            'total_room_revenue' => 100000,
            'total_pos_revenue' => 25000,
            'total_tax' => 12500,
            'total_payments' => 112500,
            'net_revenue' => 87500,
            'currency_code' => 'USD',
            'exchange_rate_to_group' => 1.000000,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('group_ledgers', [
            'branch_id' => $this->branch->id,
            'business_date' => '2026-09-14',
        ]);
    }

    public function test_can_update_group_ledger(): void
    {
        $ledger = GroupLedger::factory()->forBranch($this->branch->id)->create();

        $response = $this->actingAs($this->user)->put("/group-ledgers/{$ledger->id}", [
            'total_room_revenue' => 200000,
            'net_revenue' => 175000,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('group_ledgers', [
            'id' => $ledger->id,
            'total_room_revenue' => 200000,
            'net_revenue' => 175000,
        ]);
    }

    public function test_can_delete_group_ledger(): void
    {
        $ledger = GroupLedger::factory()->forBranch($this->branch->id)->create();

        $response = $this->actingAs($this->user)->delete("/group-ledgers/{$ledger->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('group_ledgers', ['id' => $ledger->id]);
    }

    public function test_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)->post('/group-ledgers', []);

        $response->assertSessionHasErrors([
            'business_date',
            'total_room_revenue',
            'total_pos_revenue',
            'total_tax',
            'total_payments',
            'net_revenue',
            'currency_code',
            'exchange_rate_to_group',
        ]);
    }

    public function test_cannot_access_other_branch_ledger(): void
    {
        $otherBranch = Branch::factory()->create();
        $ledger = GroupLedger::factory()->forBranch($otherBranch->id)->create();

        $response = $this->actingAs($this->user)->get("/group-ledgers/{$ledger->id}");

        $response->assertStatus(403);
    }
}
