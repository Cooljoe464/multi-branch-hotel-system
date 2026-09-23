<?php

namespace App\Events;

use App\Models\Reservation;
use App\Models\UpsellOffer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UpsellExpired implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public UpsellOffer $offer,
        public Reservation $reservation,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->offer->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'upsell.expired';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'branch_id' => $this->offer->branch_id,
            'offer_id' => $this->offer->id,
            'reservation_id' => $this->reservation->id,
            'at' => now()->toISOString(),
        ];
    }
}
