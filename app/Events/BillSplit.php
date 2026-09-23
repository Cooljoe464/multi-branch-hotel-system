<?php

namespace App\Events;

use App\Models\PosCharge;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BillSplit implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<PosCharge>  $children
     */
    public function __construct(
        public PosCharge $tab,
        public array $children,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->tab->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'pos.bill_split';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'branch_id' => $this->tab->branch_id,
            'tab_id' => $this->tab->id,
            'children' => array_map(fn (PosCharge $child) => $child->id, $this->children),
            'at' => now()->toISOString(),
        ];
    }
}
