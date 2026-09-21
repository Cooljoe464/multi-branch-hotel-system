<?php

namespace App\Events;

use App\Models\Folio;
use App\Models\Transaction;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FolioTransferred implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Transaction $source,
        public Transaction $moved,
        public Folio $masterFolio,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->masterFolio->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'folio.transferred';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'source_transaction_id' => $this->source->id,
            'moved_transaction_id' => $this->moved->id,
            'master_folio_id' => $this->masterFolio->id,
            'amount' => $this->moved->amount,
            'at' => now()->toISOString(),
        ];
    }
}
