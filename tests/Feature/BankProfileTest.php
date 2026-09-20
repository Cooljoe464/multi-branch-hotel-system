<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankProfileTest extends TestCase
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

    public function test_can_list_bank_profiles(): void
    {
        $response = $this->actingAs($this->user)->get('/bank-profiles');
        $response->assertStatus(200);
    }

    public function test_can_create_bank_profile(): void
    {
        $response = $this->actingAs($this->user)->post('/bank-profiles', [
            'bank_name' => 'Test Bank',
            'account_number' => '1234567890',
            'account_name' => 'Test Account',
            'currency_code' => 'USD',
        ]);
        $response->assertRedirect();
        $this->assertDatabaseHas('bank_profiles', ['bank_name' => 'Test Bank', 'branch_id' => $this->branch->id]);
    }
}
