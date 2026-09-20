<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CityLedgerAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CityLedgerTest extends TestCase
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

    public function test_can_list_accounts(): void
    {
        CityLedgerAccount::factory()->forBranch($this->branch->id)->create();
        $response = $this->actingAs($this->user)->get('/city-ledger');
        $response->assertStatus(200);
    }

    public function test_can_create_account(): void
    {
        $response = $this->actingAs($this->user)->post('/city-ledger', [
            'company_name' => 'ACME Corp',
            'contact_name' => 'John Doe',
            'email' => 'john@acme.com',
            'credit_limit' => 500000,
            'payment_terms_days' => 30,
        ]);
        $response->assertRedirect();
        $this->assertDatabaseHas('city_ledger_accounts', ['company_name' => 'ACME Corp', 'branch_id' => $this->branch->id]);
    }

    public function test_can_post_charge(): void
    {
        $account = CityLedgerAccount::factory()->forBranch($this->branch->id)->create(['balance_owing' => 0]);
        $response = $this->actingAs($this->user)->post("/city-ledger/{$account->id}/charge", ['amount' => 50000, 'reference' => 'INV-001']);
        $response->assertRedirect();
        $this->assertDatabaseHas('city_ledger_accounts', ['id' => $account->id, 'balance_owing' => 50000]);
    }

    public function test_can_record_payment(): void
    {
        $account = CityLedgerAccount::factory()->forBranch($this->branch->id)->create(['balance_owing' => 100000]);
        $response = $this->actingAs($this->user)->post("/city-ledger/{$account->id}/pay", ['amount' => 50000, 'reference' => 'PAY-001']);
        $response->assertRedirect();
        $this->assertDatabaseHas('city_ledger_accounts', ['id' => $account->id, 'balance_owing' => 50000]);
    }
}
