<?php

use App\Models\Branch;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\AvailabilityService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DoubleBookingTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $user;

    protected RoomType $roomType;

    protected Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->user->assignRole('Global Admin');
        $this->user->branches()->syncWithoutDetaching([$this->branch->id]);
        $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
        $this->room = Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'status' => 'available',
        ]);
    }

    public function test_cannot_book_room_with_overlapping_dates(): void
    {
        // Conflict lives in the inventory engine (the source of truth).
        app(AvailabilityService::class)->reserve(
            $this->branch,
            $this->roomType,
            now()->addDays(3)->format('Y-m-d'),
            now()->addDays(7)->format('Y-m-d'),
            $this->engineAttributes(),
            $this->room->id,
            (string) Str::uuid(),
        );

        $response = $this->actingAs($this->user)->post('/reservations', [
            'room_type_id' => $this->roomType->id,
            'room_id' => $this->room->id,
            'guest_name' => 'Jane Doe',
            'guest_email' => 'jane@example.com',
            'adults' => 2,
            'children' => 0,
            'check_in_date' => now()->addDays(5)->format('Y-m-d'),
            'check_out_date' => now()->addDays(8)->format('Y-m-d'),
        ]);

        $response->assertSessionHasErrors('room_id');
        $response->assertSessionHasErrors([
            'room_id' => 'No availability for the requested dates.',
        ]);
    }

    public function test_can_book_room_with_non_overlapping_dates(): void
    {
        Reservation::factory()->confirmed()->create([
            'branch_id' => $this->branch->id,
            'room_id' => $this->room->id,
            'room_type_id' => $this->roomType->id,
            'check_in_date' => now()->addDays(3)->format('Y-m-d'),
            'check_out_date' => now()->addDays(5)->format('Y-m-d'),
        ]);

        $response = $this->actingAs($this->user)->post('/reservations', [
            'room_type_id' => $this->roomType->id,
            'room_id' => $this->room->id,
            'guest_name' => 'Jane Doe',
            'guest_email' => 'jane@example.com',
            'adults' => 2,
            'children' => 0,
            'check_in_date' => now()->addDays(5)->format('Y-m-d'),
            'check_out_date' => now()->addDays(8)->format('Y-m-d'),
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('reservations', [
            'room_id' => $this->room->id,
            'guest_name' => 'Jane Doe',
        ]);
    }

    public function test_can_book_room_without_specific_room_selected(): void
    {
        Reservation::factory()->confirmed()->create([
            'branch_id' => $this->branch->id,
            'room_id' => $this->room->id,
            'room_type_id' => $this->roomType->id,
            'check_in_date' => now()->addDays(3)->format('Y-m-d'),
            'check_out_date' => now()->addDays(5)->format('Y-m-d'),
        ]);

        $response = $this->actingAs($this->user)->post('/reservations', [
            'room_type_id' => $this->roomType->id,
            'guest_name' => 'Jane Doe',
            'guest_email' => 'jane@example.com',
            'adults' => 2,
            'children' => 0,
            'check_in_date' => now()->addDays(3)->format('Y-m-d'),
            'check_out_date' => now()->addDays(5)->format('Y-m-d'),
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('reservations', [
            'guest_name' => 'Jane Doe',
            'room_id' => null,
        ]);
    }

    public function test_cannot_check_in_to_room_with_overlapping_reservation(): void
    {
        $reservation1 = Reservation::factory()->confirmed()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'room_id' => $this->room->id,
            'check_in_date' => now()->addDays(1)->format('Y-m-d'),
            'check_out_date' => now()->addDays(5)->format('Y-m-d'),
        ]);

        $reservation2 = Reservation::factory()->confirmed()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'room_id' => $this->room->id,
            'check_in_date' => now()->addDays(4)->format('Y-m-d'),
            'check_out_date' => now()->addDays(8)->format('Y-m-d'),
        ]);

        $response = $this->actingAs($this->user)->post("/reservations/{$reservation2->id}/check-in", [
            'room_id' => $this->room->id,
        ]);

        $response->assertSessionHasErrors('room_id');
    }

    public function test_can_check_in_room_after_existing_reservation_checkout(): void
    {
        $existingReservation = Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'room_id' => $this->room->id,
            'check_in_date' => now()->subDays(5)->format('Y-m-d'),
            'check_out_date' => now()->subDay()->format('Y-m-d'),
        ]);

        $roomType2 = RoomType::factory()->create(['branch_id' => $this->branch->id]);
        $room2 = Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $roomType2->id,
            'status' => 'available',
        ]);

        $reservation2 = Reservation::factory()->confirmed()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $roomType2->id,
            'check_in_date' => now()->format('Y-m-d'),
            'check_out_date' => now()->addDays(3)->format('Y-m-d'),
        ]);

        $response = $this->actingAs($this->user)->post("/reservations/{$reservation2->id}/check-in", [
            'room_id' => $room2->id,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('reservations', [
            'id' => $reservation2->id,
            'status' => 'checked_in',
        ]);
    }

    public function test_overlapping_detection_across_same_room_reservations(): void
    {
        app(AvailabilityService::class)->reserve(
            $this->branch,
            $this->roomType,
            '2026-10-01',
            '2026-10-04',
            $this->engineAttributes(),
            $this->room->id,
            (string) Str::uuid(),
        );

        $overlappingDates = [
            ['2026-10-03', '2026-10-06'],
            ['2026-09-30', '2026-10-02'],
            ['2026-09-30', '2026-10-06'],
            ['2026-10-02', '2026-10-03'],
        ];

        foreach ($overlappingDates as [$checkIn, $checkOut]) {
            $response = $this->actingAs($this->user)->post('/reservations', [
                'room_type_id' => $this->roomType->id,
                'room_id' => $this->room->id,
                'guest_name' => 'Overlap Test',
                'guest_email' => 'overlap@test.com',
                'adults' => 1,
                'children' => 0,
                'check_in_date' => $checkIn,
                'check_out_date' => $checkOut,
            ]);

            $response->assertSessionHasErrors('room_id');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function engineAttributes(): array
    {
        return [
            'guest_name' => 'Engine Seed',
            'adults' => 2,
            'children' => 0,
            'room_rate' => 25000,
            'total_amount' => 50000,
            'status' => 'confirmed',
            'source' => 'direct',
            'payment_status' => 'pending',
        ];
    }
}
