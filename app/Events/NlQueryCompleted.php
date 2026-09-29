<?php

namespace App\Events;

use App\Models\NlQueryLog;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NlQueryCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public NlQueryLog $log,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->log->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'nl.query.completed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'query_key' => $this->log->query_key,
            'rows' => $this->log->rows,
            'duration_ms' => $this->log->duration_ms,
        ];
    }
}
