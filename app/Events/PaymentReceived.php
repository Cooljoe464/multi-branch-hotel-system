<?php

namespace App\Events;

use App\Models\Folio;
use App\Models\Transaction;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Folio $folio,
        public Transaction $transaction,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->folio->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'folio.payment.received';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'folio_id' => $this->folio->id,
            'branch_id' => $this->folio->branch_id,
            'transaction_id' => $this->transaction->id,
            'amount' => $this->transaction->amount,
            'category' => $this->transaction->category,
            'balance' => $this->folio->outstanding_balance,
            'posted_at' => $this->transaction->created_at?->toISOString(),
        ];
    }
}
