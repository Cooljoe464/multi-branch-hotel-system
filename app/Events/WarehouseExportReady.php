<?php

namespace App\Events;

use App\Models\WarehouseManifest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WarehouseExportReady implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public WarehouseManifest $manifest,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('warehouse'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'warehouse.export.ready';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'manifest_id' => $this->manifest->id,
            'business_date' => $this->manifest->business_date->toDateString(),
            'row_counts' => $this->manifest->row_counts,
        ];
    }
}
