<?php

namespace App\Events;

use App\Models\TabletOrder;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public TabletOrder $order,
        public string $previousStatus,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('tablet.order.'.$this->order->id);
    }

    public function broadcastAs(): string
    {
        return 'order.status.updated';
    }

    /**
     * @return array{id: int, status: string, previous_status: string, payment_status: string}
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->order->id,
            'status' => $this->order->status,
            'previous_status' => $this->previousStatus,
            'payment_status' => $this->order->payment_status,
        ];
    }
}
