<?php

namespace App\Events;

use App\Models\Branch;
use App\Models\BusinessDate;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BusinessDateAdvanced implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Branch $branch,
        public BusinessDate $closedDate,
        public BusinessDate $openDate,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->branch->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'business-date.advanced';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'branch_id' => $this->branch->id,
            'closed_business_date' => $this->closedDate->business_date->toDateString(),
            'open_business_date' => $this->openDate->business_date->toDateString(),
            'advanced_at' => now()->toISOString(),
        ];
    }
}
