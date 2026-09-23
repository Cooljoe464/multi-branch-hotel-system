<?php

namespace App\Events;

use App\Models\MaintenanceTicket;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkOrderAssigned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public MaintenanceTicket $ticket,
        public ?User $assignedBy = null,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->ticket->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'maintenance.work_order_assigned';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'branch_id' => $this->ticket->branch_id,
            'ticket_id' => $this->ticket->id,
            'assignee_id' => $this->ticket->assigned_to,
            'at' => now()->toISOString(),
        ];
    }
}
