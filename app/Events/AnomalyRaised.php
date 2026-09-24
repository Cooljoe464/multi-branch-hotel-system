<?php

namespace App\Events;

use App\Models\AnomalyFinding;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AnomalyRaised implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public AnomalyFinding $finding,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->finding->branch_id.'.audit'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'anomaly.raised';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'finding_id' => $this->finding->id,
            'branch_id' => $this->finding->branch_id,
            'rule_code' => $this->finding->rule_code,
            'score' => $this->finding->score,
        ];
    }
}
