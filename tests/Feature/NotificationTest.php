<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Notifications\CheckInNotification;
use App\Notifications\CheckoutNotification;
use App\Notifications\PreArrivalNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $user;

    protected RoomType $roomType;

    protected Room $room;

    protected Guest $guest;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->branch = Branch::factory()->create();
        $this->user = $this->makeAdminUser($this->branch);
        $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
        $this->room = Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
        ]);
        $this->guest = Guest::factory()->create([
            'email' => 'john@example.com',
        ]);
    }

    public function test_pre_arrival_notification_is_sent(): void
    {
        $reservation = Reservation::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'guest_id' => $this->guest->id,
            'guest_email' => 'john@example.com',
        ]);

        Notification::send($this->guest, new PreArrivalNotification($reservation));

        Notification::assertSentTo(
            $this->guest,
            PreArrivalNotification::class
        );
    }

    public function test_check_in_notification_is_sent(): void
    {
        $reservation = Reservation::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'room_id' => $this->room->id,
            'guest_id' => $this->guest->id,
            'guest_email' => 'john@example.com',
            'status' => 'checked_in',
        ]);

        Notification::send($this->guest, new CheckInNotification($reservation));

        Notification::assertSentTo(
            $this->guest,
            CheckInNotification::class
        );
    }

    public function test_checkout_notification_is_sent(): void
    {
        $reservation = Reservation::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'guest_id' => $this->guest->id,
            'guest_email' => 'john@example.com',
            'status' => 'checked_out',
        ]);

        Notification::send($this->guest, new CheckoutNotification($reservation));

        Notification::assertSentTo(
            $this->guest,
            CheckoutNotification::class
        );
    }

    public function test_check_in_sends_notification(): void
    {
        Notification::fake();

        $reservation = Reservation::factory()->confirmed()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'guest_id' => $this->guest->id,
            'guest_email' => 'john@example.com',
        ]);

        $this->actingAs($this->user)
            ->post("/reservations/{$reservation->id}/check-in", [
                'room_id' => $this->room->id,
            ]);

        Notification::assertSentTo(
            $this->guest,
            CheckInNotification::class
        );
    }

    public function test_check_out_sends_notification(): void
    {
        Notification::fake();

        $reservation = Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'room_id' => $this->room->id,
            'guest_id' => $this->guest->id,
            'guest_email' => 'john@example.com',
        ]);

        $this->actingAs($this->user)
            ->post("/reservations/{$reservation->id}/check-out");

        Notification::assertSentTo(
            $this->guest,
            CheckoutNotification::class
        );
    }
}
