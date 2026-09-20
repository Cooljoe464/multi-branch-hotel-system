<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Room;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class TaskSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();
        $users = User::all();

        $housekeepers = $users->filter(function ($user) {
            return $user->hasRole('Housekeeper');
        });

        foreach ($branches as $branch) {
            $rooms = Room::where('branch_id', $branch->id)->get();

            if ($rooms->isEmpty()) {
                continue;
            }

            $this->createTasks($branch, $rooms, $housekeepers);
        }
    }

    /**
     * @param  Collection<int, Room>  $rooms
     * @param  Collection<int, User>  $housekeepers
     */
    private function createTasks(Branch $branch, Collection $rooms, Collection $housekeepers): void
    {
        $taskTypes = ['cleaning', 'deep_clean', 'turnover', 'inspection', 'laundry', 'maintenance_request'];
        $priorities = ['low', 'normal', 'normal', 'high', 'urgent'];
        $statuses = ['pending', 'pending', 'pending', 'in_progress', 'completed'];

        for ($i = 0; $i < 15; $i++) {
            $room = $rooms[$i % $rooms->count()] ?? null;

            if (! $room) {
                continue;
            }

            $assignee = $housekeepers->random();
            $type = $taskTypes[$i % count($taskTypes)];
            $status = $statuses[$i % count($statuses)];
            $priority = $priorities[$i % count($priorities)];

            $estimatedMinutes = match ($type) {
                'cleaning' => 30,
                'deep_clean' => 90,
                'turnover' => 45,
                'inspection' => 20,
                'laundry' => 60,
                'maintenance_request' => 120,
            };

            $task = Task::firstOrCreate(
                [
                    'branch_id' => $branch->id,
                    'room_id' => $room->id,
                    'type' => $type,
                ],
                [
                    'assigned_to' => $assignee->id,
                    'priority' => $priority,
                    'status' => $status,
                    'description' => $this->getDescription($type, $room->number),
                    'estimated_minutes' => $estimatedMinutes,
                ]
            );

            if ($status === 'in_progress') {
                $task->update(['started_at' => now()->subMinutes(rand(5, 30))]);
            }

            if ($status === 'completed') {
                $task->update([
                    'started_at' => now()->subMinutes(rand(30, 60)),
                    'completed_at' => now()->subMinutes(rand(5, 25)),
                    'actual_minutes' => $estimatedMinutes - rand(5, 15),
                ]);
            }
        }
    }

    private function getDescription(string $type, string $roomNumber): string
    {
        return match ($type) {
            'cleaning' => "Standard cleaning for room {$roomNumber}",
            'deep_clean' => "Deep cleaning required for room {$roomNumber}",
            'turnover' => "Room {$roomNumber} turnover for new guest",
            'inspection' => "Routine inspection for room {$roomNumber}",
            'laundry' => "Laundry service for room {$roomNumber}",
            'maintenance_request' => "Maintenance issue reported in room {$roomNumber}",
            default => "Task for room {$roomNumber}",
        };
    }
}
