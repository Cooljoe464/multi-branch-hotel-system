<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
    }

    public function test_guest_model_can_be_created(): void
    {
        $guest = Guest::factory()->create([
            'email' => 'john@example.com',
        ]);

        $this->assertDatabaseHas('guests', [
            'email' => 'john@example.com',
        ]);
    }

    public function test_guest_has_full_name_accessor(): void
    {
        $guest = Guest::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $this->assertEquals('John Doe', $guest->full_name);
    }

    public function test_guest_can_have_preferences(): void
    {
        $guest = Guest::factory()->create();

        $guest->setPreference('room', 'floor', 'high', 'Prefers high floors');

        $this->assertDatabaseHas('guest_preferences', [
            'guest_id' => $guest->id,
            'category' => 'room',
            'key' => 'floor',
            'value' => 'high',
        ]);

        $this->assertEquals('high', $guest->getPreference('room', 'floor'));
    }

    public function test_guest_vip_status_evaluation(): void
    {
        $guest = Guest::factory()->create(['vip_status' => 'none']);

        // Simulate stays to reach silver
        $guest->incrementStay(3, 150000);

        $guest->refresh();
        $this->assertEquals('silver', $guest->vip_status);
    }

    public function test_guest_search_scope(): void
    {
        Guest::factory()->create(['first_name' => 'John', 'last_name' => 'Smith', 'email' => 'john@test.com']);
        Guest::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@test.com']);
        Guest::factory()->create(['first_name' => 'Bob', 'last_name' => 'Brown', 'email' => 'bob@test.com']);

        $results = Guest::search('John')->get();
        $this->assertCount(1, $results);

        $results = Guest::search('Brown')->get();
        $this->assertCount(1, $results);
    }
}
