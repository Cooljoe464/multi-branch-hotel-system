<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\MaintenanceTicket;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class MaintenanceTicketSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();
        $users = User::all();

        foreach ($branches as $branch) {
            $rooms = Room::where('branch_id', $branch->id)->get();
            $reporters = $users->filter(function ($user) use ($branch) {
                return $user->branches->contains('id', $branch->id);
            });

            if ($rooms->isEmpty() || $reporters->isEmpty()) {
                continue;
            }

            $this->createTickets($branch, $rooms, $reporters);
        }
    }

    /**
     * @param  Collection<int, Room>  $rooms
     * @param  Collection<int, User>  $reporters
     */
    private function createTickets(Branch $branch, Collection $rooms, Collection $reporters): void
    {
        $categories = ['plumbing', 'electrical', 'hvac', 'furniture', 'appliance', 'structural', 'other'];
        $statuses = ['open', 'open', 'in_progress', 'in_progress', 'completed'];
        $priorities = ['low', 'normal', 'normal', 'high', 'urgent'];

        $issues = [
            'plumbing' => ['Leaking faucet', 'Clogged drain', 'Running toilet', 'Low water pressure'],
            'electrical' => ['Lights not working', 'Power outlet issue', 'Switch malfunction', 'Tripped breaker'],
            'hvac' => ['AC not cooling', 'Heater not working', 'Strange noise from HVAC', 'Thermostat issue'],
            'furniture' => ['Broken chair', 'Damaged table', 'Loose handle', 'Scratched surface'],
            'appliance' => ['TV not turning on', 'Minibar not cooling', 'Coffee maker broken', 'Hair dryer issue'],
            'structural' => ['Cracked wall', 'Leaking ceiling', 'Broken window', 'Damaged floor'],
            'other' => ['Pest control needed', 'Odor issue', 'Noise complaint', 'Fire alarm test'],
        ];

        for ($i = 0; $i < 8; $i++) {
            $room = $rooms[$i % $rooms->count()] ?? null;

            if (! $room) {
                continue;
            }

            $category = $categories[$i % count($categories)];
            $status = $statuses[$i % count($statuses)];
            $priority = $priorities[$i % count($priorities)];
            $reporter = $reporters->random();

            $issueList = $issues[$category];
            $title = $issueList[$i % count($issueList)];

            $isLocked = in_array($status, ['open', 'in_progress']) && $priority === 'urgent';

            $estimatedCost = match ($category) {
                'plumbing' => rand(5000, 30000),
                'electrical' => rand(7500, 50000),
                'hvac' => rand(10000, 80000),
                'furniture' => rand(20000, 100000),
                'appliance' => rand(10000, 60000),
                'structural' => rand(30000, 150000),
                'other' => rand(5000, 40000),
            };

            $ticket = MaintenanceTicket::firstOrCreate(
                [
                    'branch_id' => $branch->id,
                    'room_id' => $room->id,
                    'category' => $category,
                ],
                [
                    'reported_by' => $reporter->id,
                    'assigned_to' => $status === 'in_progress' ? $reporter->id : null,
                    'priority' => $priority,
                    'status' => $status,
                    'title' => $title,
                    'description' => "{$title} reported in room {$room->number}. Requires immediate attention.",
                    'is_room_locked' => $isLocked,
                    'estimated_cost' => $estimatedCost,
                ]
            );

            if ($status === 'in_progress') {
                $ticket->update(['started_at' => now()->subHours(rand(1, 24))]);
            }

            if ($status === 'completed') {
                $ticket->update([
                    'started_at' => now()->subDays(rand(1, 3)),
                    'completed_at' => now()->subHours(rand(1, 12)),
                    'actual_cost' => $estimatedCost - rand(-50, 50),
                    'resolution_notes' => 'Issue resolved. All systems operational.',
                ]);
            }
        }
    }
}
