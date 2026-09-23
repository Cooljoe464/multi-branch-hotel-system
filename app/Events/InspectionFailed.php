<?php

namespace App\Events;

use App\Models\HousekeepingTask;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InspectionFailed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public HousekeepingTask $task,
        public HousekeepingTask $retry,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->task->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'hk.inspection_failed';
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
            'score' => $this->task->inspection_score,
            'retry_task_id' => $this->retry->id,
            'at' => now()->toISOString(),
        ];
    }
}
