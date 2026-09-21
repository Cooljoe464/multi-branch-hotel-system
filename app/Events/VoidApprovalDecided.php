<?php

namespace App\Events;

use App\Models\VoidRefundApproval;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VoidApprovalDecided implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public VoidRefundApproval $approval,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.system'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'void.approval.decided';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'approval_id' => $this->approval->id,
            'status' => $this->approval->status,
            'approved_by' => $this->approval->approved_by,
            'at' => now()->toISOString(),
        ];
    }
}
