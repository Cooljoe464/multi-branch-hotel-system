<?php

namespace App\Events;

use App\Models\PostStaySurvey;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SurveyReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public PostStaySurvey $survey,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('crm.surveys'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'survey.received';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'survey_id' => $this->survey->id,
            'reservation_id' => $this->survey->reservation_id,
            'nps' => $this->survey->nps,
            'sentiment' => $this->survey->sentiment,
            'at' => now()->toISOString(),
        ];
    }
}
