<?php

namespace App\Events;

use App\Models\GuestIdentityDocument;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OcrCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public GuestIdentityDocument $document,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('crm.documents'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ocr.completed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'document_id' => $this->document->id,
            'guest_id' => $this->document->guest_id,
            'at' => now()->toISOString(),
        ];
    }
}
