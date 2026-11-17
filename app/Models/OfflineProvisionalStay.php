<?php

namespace App\Models;

use Database\Factories\OfflineProvisionalStayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'branch_id',
    'offline_command_id',
    'device_id',
    'room_id',
    'room_type_id',
    'guest_name',
    'guest_email',
    'check_in_date',
    'check_out_date',
    'status',
    'metadata',
])]
class OfflineProvisionalStay extends Model
{
    /** @use HasFactory<OfflineProvisionalStayFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<OfflineCommand, $this> */
    public function command(): BelongsTo
    {
        return $this->belongsTo(OfflineCommand::class, 'offline_command_id');
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
