<?php

namespace App\Events;

use App\Models\HousekeepingTask;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HkTaskAssigned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public HousekeepingTask $task,
        public ?User $assignedBy = null,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->task->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'hk.task_assigned';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'branch_id' => $this->task->branch_id,
            'task_id' => $this->task->id,
            'room_id' => $this->task->room_id,
            'assignee_id' => $this->task->assignee_id,
            'at' => now()->toISOString(),
        ];
    }
}
