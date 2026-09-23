<?php

namespace App\Events;

use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PurchaseOrderReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public PurchaseOrder $order,
        public GoodsReceipt $receipt,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->order->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'inventory.po_received';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'branch_id' => $this->order->branch_id,
            'purchase_order_id' => $this->order->id,
            'receipt_id' => $this->receipt->id,
            'at' => now()->toISOString(),
        ];
    }
}
