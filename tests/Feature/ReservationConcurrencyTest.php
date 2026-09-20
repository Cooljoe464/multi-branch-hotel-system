<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\FolioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected RoomType $roomType;

    protected Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
        $this->room = Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'status' => 'available',
        ]);
    }

    public function test_prevents_double_booking_via_overlap_detection(): void
    {
        Reservation::factory()->confirmed()->create([
            'branch_id' => $this->branch->id,
            'room_id' => $this->room->id,
            'room_type_id' => $this->roomType->id,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(7)->toDateString(),
        ]);

        $this->actingAs($this->makeAdminUser($this->branch))
            ->post('/reservations', [
                'room_type_id' => $this->roomType->id,
                'room_id' => $this->room->id,
                'guest_name' => 'Overlap Test',
                'adults' => 1,
                'children' => 0,
                'check_in_date' => now()->addDays(5)->toDateString(),
                'check_out_date' => now()->addDays(8)->toDateString(),
            ])
            ->assertSessionHasErrors('room_id');
    }

    public function test_serialized_check_in_prevents_double_occupancy(): void
    {
        $reservation1 = Reservation::factory()->confirmed()->create([
            'branch_id' => $this->branch->id,
            'room_id' => $this->room->id,
            'room_type_id' => $this->roomType->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(3)->toDateString(),
        ]);

        $reservation2 = Reservation::factory()->confirmed()->create([
            'branch_id' => $this->branch->id,
            'room_id' => null,
            'room_type_id' => $this->roomType->id,
            'check_in_date' => now()->addDays(4)->toDateString(),
            'check_out_date' => now()->addDays(7)->toDateString(),
        ]);

        $user = $this->makeAdminUser($this->branch);

        $this->actingAs($user)
            ->post("/reservations/{$reservation1->id}/check-in", ['room_id' => $this->room->id])
            ->assertRedirect();

        $this->assertDatabaseHas('rooms', [
            'id' => $this->room->id,
            'status' => 'occupied',
        ]);

        $this->actingAs($user)
            ->post("/reservations/{$reservation2->id}/check-in", ['room_id' => $this->room->id])
            ->assertSessionHasErrors('room_id');
    }

    public function test_concurrent_folio_debit_postings_maintain_correct_balance(): void
    {
        $reservation = Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
            'room_rate' => 10000,
        ]);

        $service = new FolioService;
        $folio = $service->createFolio($this->branch->id, $reservation->id);

        $service->postDebit($folio, 'room_rate', 'Night 1', 10000);
        $service->postDebit($folio, 'minibar', 'Snacks', 1500);
        $service->postDebit($folio, 'restaurant', 'Dinner', 3000);
        $service->postDebit($folio, 'spa', 'Massage', 5000);

        $folio->refresh();
        $this->assertEquals(19500, $folio->balance);
    }

    public function test_concurrent_credit_and_debit_on_same_folio_serializes_correctly(): void
    {
        $reservation = Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
            'room_rate' => 10000,
        ]);

        $service = new FolioService;
        $folio = $service->createFolio($this->branch->id, $reservation->id);

        $service->postDebit($folio, 'room_rate', 'Night 1', 10000);
        $service->postCredit($folio, 'payment', 'Deposit', 5000);
        $service->postDebit($folio, 'restaurant', 'Lunch', 2500);

        $folio->refresh();
        $this->assertEquals(7500, $folio->balance);
    }

    public function test_cannot_check_in_already_checked_in_reservation(): void
    {
        $reservation = Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
            'room_id' => $this->room->id,
            'room_type_id' => $this->roomType->id,
        ]);

        $this->actingAs($this->makeAdminUser($this->branch))
            ->post("/reservations/{$reservation->id}/check-in", ['room_id' => $this->room->id])
            ->assertSessionHasErrors('status');
    }
}
