<?php

namespace App\Events;

use App\Models\MenuItem;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MenuItemStockToggled implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public MenuItem $menuItem,
        public bool $isAvailable,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('branch.'.$this->menuItem->branch_id.'.menu');
    }

    public function broadcastAs(): string
    {
        return 'menu.stock.toggled';
    }

    /**
     * @return array{id: int, name: string, is_available: bool}
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->menuItem->id,
            'name' => $this->menuItem->name,
            'is_available' => $this->isAvailable,
        ];
    }
}
