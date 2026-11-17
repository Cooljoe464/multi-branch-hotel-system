<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Guest;
use App\Models\Room;
use App\Models\RoomType;
use Database\Seeders\ReservationSeeder;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('seeds reservations without broadcasting room status changes', function () {
    $branch = Branch::factory()->create();
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    Room::factory()->count(3)->create([
        'branch_id' => $branch->id,
        'room_type_id' => $roomType->id,
    ]);
    Guest::factory()->count(6)->create();

    Queue::fake();

    $this->seed(ReservationSeeder::class);

    Queue::assertNotPushed(BroadcastEvent::class);
});
