<?php

namespace App\Events;

use App\Models\FiscalDocument;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FiscalDocumentIssued implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public FiscalDocument $document,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->document->branch_id.'.finance'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'fiscal.issued';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'fiscal_document_id' => $this->document->id,
            'branch_id' => $this->document->branch_id,
            'folio_id' => $this->document->folio_id,
            'irn' => $this->document->irn,
            'issued_at' => now()->toISOString(),
        ];
    }
}
