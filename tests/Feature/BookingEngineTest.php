<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingEngineTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected RoomType $roomType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
    }

    public function test_booking_engine_page_loads(): void
    {
        $response = $this->get('/book');

        $response->assertStatus(200);
    }

    public function test_search_availability_endpoint(): void
    {
        Room::factory()->count(3)->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'status' => 'available',
        ]);

        $response = $this->postJson('/book/search', [
            'branch_id' => $this->branch->id,
            'check_in' => now()->addDays(7)->format('Y-m-d'),
            'check_out' => now()->addDays(10)->format('Y-m-d'),
            'adults' => 2,
            'children' => 0,
        ]);

        $response->assertOk();
        $response->assertJsonFragment([
            'id' => $this->roomType->id,
        ]);
    }

    public function test_booking_can_be_created(): void
    {
        Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'status' => 'available',
        ]);

        $response = $this->postJson('/book', [
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'check_in' => now()->addDays(7)->format('Y-m-d'),
            'check_out' => now()->addDays(10)->format('Y-m-d'),
            'adults' => 2,
            'children' => 0,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'phone' => '555-0123',
        ]);

        $response->assertCreated();
        $response->assertJsonStructure([
            'reservation' => ['confirmation_number', 'status'],
        ]);

        $this->assertDatabaseHas('reservations', [
            'branch_id' => $this->branch->id,
            'guest_email' => 'john@example.com',
            'status' => 'confirmed',
        ]);

        // Guest should be auto-created
        $this->assertDatabaseHas('guests', [
            'email' => 'john@example.com',
        ]);
    }

    public function test_guest_folio_page_loads(): void
    {
        $reservation = Reservation::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
        ]);

        $response = $this->get("/guest/folio/{$reservation->confirmation_number}");

        $response->assertStatus(200);
    }

    public function test_guest_lookup_works(): void
    {
        $reservation = Reservation::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'guest_email' => 'john@example.com',
        ]);

        $response = $this->post('/guest/lookup', [
            'email' => 'john@example.com',
            'confirmation' => $reservation->confirmation_number,
        ]);

        $response->assertStatus(200);
    }

    public function test_guest_lookup_fails_with_wrong_info(): void
    {
        $reservation = Reservation::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'guest_email' => 'john@example.com',
        ]);

        $response = $this->post('/guest/lookup', [
            'email' => 'wrong@example.com',
            'confirmation' => $reservation->confirmation_number,
        ]);

        $response->assertSessionHasErrors('email');
    }
}
