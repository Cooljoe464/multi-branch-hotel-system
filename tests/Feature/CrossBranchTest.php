<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrossBranchTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch1;

    protected Branch $branch2;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch1 = Branch::factory()->create();
        $this->branch2 = Branch::factory()->create();
        $this->user = $this->makeAdminUser($this->branch1);
        $this->user->branches()->attach($this->branch2->id);
    }

    public function test_cross_branch_search_page_loads(): void
    {
        $roomType1 = RoomType::factory()->create(['branch_id' => $this->branch1->id]);
        $roomType2 = RoomType::factory()->create(['branch_id' => $this->branch2->id]);

        Room::factory()->count(2)->create([
            'branch_id' => $this->branch1->id,
            'room_type_id' => $roomType1->id,
        ]);

        Room::factory()->count(2)->create([
            'branch_id' => $this->branch2->id,
            'room_type_id' => $roomType2->id,
        ]);

        $checkIn = now()->addDays(7)->format('Y-m-d');
        $checkOut = now()->addDays(10)->format('Y-m-d');

        $response = $this->actingAs($this->user)->get(
            "/reservations/cross-branch/search?check_in={$checkIn}&check_out={$checkOut}&adults=2"
        );

        $response->assertStatus(200);
    }

    public function test_reservation_can_be_created_for_sister_branch(): void
    {
        $roomType = RoomType::factory()->create(['branch_id' => $this->branch2->id]);

        $response = $this->actingAs($this->user)->post('/reservations', [
            'branch_id' => $this->branch2->id,
            'room_type_id' => $roomType->id,
            'guest_name' => 'John Doe',
            'guest_email' => 'john@example.com',
            'adults' => 2,
            'children' => 0,
            'check_in_date' => now()->addDays(7)->format('Y-m-d'),
            'check_out_date' => now()->addDays(10)->format('Y-m-d'),
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('reservations', [
            'branch_id' => $this->branch2->id,
            'guest_name' => 'John Doe',
        ]);
    }
}
