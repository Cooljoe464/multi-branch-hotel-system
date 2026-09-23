<?php

namespace App\Events;

use App\Models\AccountingExport;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AccountingExportFailed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public AccountingExport $export,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->export->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'accounting.export.failed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'export_id' => $this->export->id,
            'branch_id' => $this->export->branch_id,
            'business_date' => $this->export->business_date->toDateString(),
            'provider' => $this->export->provider,
            'last_error' => $this->export->last_error,
        ];
    }
}
