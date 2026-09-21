<?php

namespace App\Events;

use App\Models\ChannelMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChannelPushFailed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ChannelMessage $message,
        public string $error,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->message->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'channel.push_failed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'branch_id' => $this->message->branch_id,
            'message_id' => $this->message->id,
            'channel' => $this->message->channel,
            'attempts' => $this->message->attempts,
            'error' => $this->error,
            'at' => now()->toISOString(),
        ];
    }
}
