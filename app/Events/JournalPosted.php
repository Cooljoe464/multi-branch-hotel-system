<?php

namespace App\Events;

use App\Models\JournalEntry;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class JournalPosted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public JournalEntry $entry,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->entry->branch_id.'.finance'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'journal.posted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'journal_id' => $this->entry->id,
            'branch_id' => $this->entry->branch_id,
            'business_date' => $this->entry->business_date->toDateString(),
            'event' => $this->entry->event,
            'debit_account' => $this->entry->debit_account,
            'credit_account' => $this->entry->credit_account,
            'amount_minor' => $this->entry->amount_minor,
            'posted_at' => $this->entry->posted_at?->toISOString(),
        ];
    }
}
