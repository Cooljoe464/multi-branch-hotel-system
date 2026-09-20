<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Guest;
use App\Models\RoomType;
use App\Models\User;
use Database\Factories\ChannelProviderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelTest extends TestCase
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

    public function test_can_list_channel_providers(): void
    {
        ChannelProviderFactory::new()->forBranch($this->branch->id)->create();

        $response = $this->actingAs($this->user)->get('/channels');

        $response->assertStatus(200);
    }

    public function test_can_sync_channel_provider(): void
    {
        $provider = ChannelProviderFactory::new()->forBranch($this->branch->id)->create();

        $response = $this->actingAs($this->user)->post("/channels/{$provider->id}/sync");

        $response->assertRedirect();
        $provider->refresh();
        $this->assertNotNull($provider->last_sync_at);
    }

    public function test_cannot_sync_other_branch_provider(): void
    {
        $otherBranch = Branch::factory()->create();
        $provider = ChannelProviderFactory::new()->forBranch($otherBranch->id)->create();

        $response = $this->actingAs($this->user)->post("/channels/{$provider->id}/sync");

        $response->assertStatus(403);
    }

    public function test_can_pull_reservations(): void
    {
        $provider = ChannelProviderFactory::new()->forBranch($this->branch->id)->create();

        $response = $this->actingAs($this->user)->post("/channels/{$provider->id}/pull");

        $response->assertRedirect();
        $this->assertDatabaseHas('channel_reservations', [
            'channel_provider_id' => $provider->id,
            'sync_status' => 'pending',
        ]);
    }

    public function test_cannot_pull_from_other_branch_provider(): void
    {
        $otherBranch = Branch::factory()->create();
        $provider = ChannelProviderFactory::new()->forBranch($otherBranch->id)->create();

        $response = $this->actingAs($this->user)->post("/channels/{$provider->id}/pull");

        $response->assertStatus(403);
    }

    public function test_can_list_crs_reservations(): void
    {
        $response = $this->actingAs($this->user)->get('/crs');

        $response->assertStatus(200);
    }

    public function test_can_create_crs_reservation(): void
    {
        $roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
        $guest = Guest::factory()->create();

        $response = $this->actingAs($this->user)->post('/crs', [
            'branch_id' => $this->branch->id,
            'guest_name' => 'John Smith',
            'guest_email' => $guest->email,
            'room_type_id' => $roomType->id,
            'check_in_date' => now()->addDay()->format('Y-m-d'),
            'check_out_date' => now()->addDays(3)->format('Y-m-d'),
            'adults' => 2,
            'children' => 0,
            'room_rate' => 15000,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('reservations', [
            'branch_id' => $this->branch->id,
            'source' => 'crs',
            'status' => 'confirmed',
        ]);
    }

    public function test_crs_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)->post('/crs', []);

        $response->assertSessionHasErrors([
            'branch_id',
            'guest_name',
            'guest_email',
            'room_type_id',
            'check_in_date',
            'check_out_date',
            'adults',
            'room_rate',
        ]);
    }

    public function test_crs_rejects_other_branch_access(): void
    {
        $otherBranch = Branch::factory()->create();
        $roomType = RoomType::factory()->create(['branch_id' => $otherBranch->id]);
        $guest = Guest::factory()->create();

        $response = $this->actingAs($this->user)->post('/crs', [
            'branch_id' => $otherBranch->id,
            'guest_name' => 'John Smith',
            'guest_email' => $guest->email,
            'room_type_id' => $roomType->id,
            'check_in_date' => now()->addDay()->format('Y-m-d'),
            'check_out_date' => now()->addDays(3)->format('Y-m-d'),
            'adults' => 2,
            'children' => 0,
            'room_rate' => 15000,
        ]);

        $response->assertStatus(403);
    }
}
