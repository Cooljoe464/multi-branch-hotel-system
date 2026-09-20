<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $user;

    protected RoomType $roomType;

    protected Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->user = $this->makeAdminUser($this->branch);
        $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
        $this->room = Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'status' => 'available',
        ]);
    }

    public function test_can_list_reservations(): void
    {
        Reservation::factory()->count(3)->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
        ]);

        $response = $this->actingAs($this->user)->get('/reservations');

        $response->assertStatus(200);
    }

    public function test_can_create_reservation(): void
    {
        $response = $this->actingAs($this->user)->post('/reservations', [
            'room_type_id' => $this->roomType->id,
            'guest_name' => 'John Doe',
            'guest_email' => 'john@example.com',
            'adults' => 2,
            'children' => 0,
            'check_in_date' => now()->addDay()->format('Y-m-d'),
            'check_out_date' => now()->addDays(3)->format('Y-m-d'),
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('reservations', [
            'branch_id' => $this->branch->id,
            'guest_name' => 'John Doe',
            'status' => 'confirmed',
        ]);
    }

    public function test_can_check_in_reservation(): void
    {
        $reservation = Reservation::factory()->confirmed()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
        ]);

        $response = $this->actingAs($this->user)->post("/reservations/{$reservation->id}/check-in", [
            'room_id' => $this->room->id,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => 'checked_in',
        ]);
        $this->assertDatabaseHas('rooms', [
            'id' => $this->room->id,
            'status' => 'occupied',
        ]);
    }

    public function test_can_check_out_reservation(): void
    {
        $reservation = Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'room_id' => $this->room->id,
        ]);

        $response = $this->actingAs($this->user)->post("/reservations/{$reservation->id}/check-out");

        $response->assertRedirect();
        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => 'checked_out',
        ]);
        $this->assertDatabaseHas('rooms', [
            'id' => $this->room->id,
            'status' => 'dirty',
        ]);
    }

    public function test_can_cancel_reservation(): void
    {
        $reservation = Reservation::factory()->confirmed()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
        ]);

        $response = $this->actingAs($this->user)->post("/reservations/{$reservation->id}/cancel");

        $response->assertRedirect();
        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_cannot_check_in_already_checked_in_reservation(): void
    {
        $reservation = Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
        ]);

        $response = $this->actingAs($this->user)->post("/reservations/{$reservation->id}/check-in", [
            'room_id' => $this->room->id,
        ]);

        $response->assertSessionHasErrors('status');
    }
}
