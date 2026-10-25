<?php

namespace App\Events;

use App\Models\ConciergeMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConciergeReplied implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ConciergeMessage $message) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $thread = $this->message->thread;

        return [
            new PrivateChannel('branch.'.$thread->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'concierge.replied';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'thread_id' => $this->message->concierge_thread_id,
            'message_id' => $this->message->id,
            'role' => $this->message->role,
            'body' => $this->message->body,
            'created_at' => $this->message->created_at?->toIso8601String(),
        ];
    }
}
