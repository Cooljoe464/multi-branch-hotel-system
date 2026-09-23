<?php

namespace App\Events;

use App\Models\DiningTable;
use App\Models\PosCharge;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TableSeated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public DiningTable $table,
        public PosCharge $tab,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->table->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'pos.table_seated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'branch_id' => $this->table->branch_id,
            'table_id' => $this->table->id,
            'table_code' => $this->table->code,
            'tab_id' => $this->tab->id,
            'at' => now()->toISOString(),
        ];
    }
}
