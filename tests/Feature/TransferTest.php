<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\TransferRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransferTest extends TestCase
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

    public function test_can_list_transfers(): void
    {
        $toBranch = Branch::factory()->create();
        TransferRequest::factory()->fromBranch($this->branch->id)->toBranch($toBranch->id)->create();
        $response = $this->actingAs($this->user)->get('/transfers');
        $response->assertStatus(200);
    }

    public function test_can_create_transfer(): void
    {
        $toBranch = Branch::factory()->create();
        $response = $this->actingAs($this->user)->post('/transfers', [
            'to_branch_id' => $toBranch->id,
            'items' => [['name' => 'Towels', 'quantity' => 10]],
        ]);
        $response->assertRedirect();
        $this->assertDatabaseHas('transfer_requests', ['from_branch_id' => $this->branch->id, 'to_branch_id' => $toBranch->id]);
    }

    public function test_can_approve_transfer(): void
    {
        $toBranch = Branch::factory()->create();
        $this->user->branches()->syncWithoutDetaching([$toBranch->id]);
        $transfer = TransferRequest::factory()->fromBranch($this->branch->id)->toBranch($toBranch->id)->pending()->create();
        $response = $this->actingAs($this->user)->post("/transfers/{$transfer->id}/approve");
        $response->assertRedirect();
        $this->assertDatabaseHas('transfer_requests', ['id' => $transfer->id, 'status' => 'approved']);
    }

    public function test_can_ship_transfer(): void
    {
        $toBranch = Branch::factory()->create();
        $transfer = TransferRequest::factory()->fromBranch($this->branch->id)->toBranch($toBranch->id)->approved()->create();
        $response = $this->actingAs($this->user)->post("/transfers/{$transfer->id}/ship");
        $response->assertRedirect();
        $this->assertDatabaseHas('transfer_requests', ['id' => $transfer->id, 'status' => 'in_transit']);
    }

    public function test_can_receive_transfer(): void
    {
        $toBranch = Branch::factory()->create();
        $this->user->branches()->syncWithoutDetaching([$toBranch->id]);
        $transfer = TransferRequest::factory()->fromBranch($this->branch->id)->toBranch($toBranch->id)->inTransit()->create();
        $response = $this->actingAs($this->user)->post("/transfers/{$transfer->id}/receive");
        $response->assertRedirect();
        $this->assertDatabaseHas('transfer_requests', ['id' => $transfer->id, 'status' => 'received']);
    }
}
