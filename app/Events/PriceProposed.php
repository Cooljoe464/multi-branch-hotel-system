<?php

namespace App\Events;

use App\Models\PriceRecommendation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PriceProposed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public PriceRecommendation $recommendation,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->recommendation->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'price.proposed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'recommendation_id' => $this->recommendation->id,
            'stay_date' => $this->recommendation->stay_date->toDateString(),
            'recommended_minor' => $this->recommendation->recommended_minor,
        ];
    }
}
