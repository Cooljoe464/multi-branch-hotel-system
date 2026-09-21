<?php

namespace App\Events;

use App\Models\VoidRefundApproval;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VoidApprovalRequested implements ShouldBroadcast
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
        return 'void.approval.requested';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'approval_id' => $this->approval->id,
            'subject_type' => $this->approval->subject_type,
            'subject_id' => $this->approval->subject_id,
            'reason_code_id' => $this->approval->reason_code_id,
            'requested_by' => $this->approval->requested_by,
            'at' => now()->toISOString(),
        ];
    }
}
