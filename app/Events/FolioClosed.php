<?php

namespace App\Events;

use App\Models\Folio;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FolioClosed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Folio $folio,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->folio->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'folio.closed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'folio_id' => $this->folio->id,
            'branch_id' => $this->folio->branch_id,
            'status' => $this->folio->status,
            'closed_at' => $this->folio->closed_at?->toISOString(),
        ];
    }
}
