<?php

namespace App\Events;

use App\Models\PosCharge;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CourseFired implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public PosCharge $tab,
        public string $course,
        public int $kotCount,
        public ?User $firedBy = null,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->tab->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'pos.course_fired';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'branch_id' => $this->tab->branch_id,
            'tab_id' => $this->tab->id,
            'course' => $this->course,
            'kot_count' => $this->kotCount,
            'at' => now()->toISOString(),
        ];
    }
}
