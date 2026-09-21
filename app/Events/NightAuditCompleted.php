<?php

namespace App\Events;

use App\Models\NightAuditRun;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NightAuditCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public NightAuditRun $run,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->run->branch_id.'.audit'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'night-audit.completed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'run_id' => $this->run->id,
            'branch_id' => $this->run->branch_id,
            'business_date' => $this->run->business_date->toDateString(),
            'result' => $this->run->result,
            'at' => now()->toISOString(),
        ];
    }
}
