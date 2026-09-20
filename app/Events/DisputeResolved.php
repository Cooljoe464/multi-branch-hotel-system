<?php

namespace App\Events;

use App\Models\FolioDispute;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DisputeResolved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public FolioDispute $dispute,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->dispute->folio->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'folio.dispute.resolved';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'dispute_id' => $this->dispute->id,
            'folio_id' => $this->dispute->folio_id,
            'branch_id' => $this->dispute->folio->branch_id,
            'status' => $this->dispute->status,
            'resolved_at' => $this->dispute->resolved_at?->toISOString(),
        ];
    }
}
