<?php

namespace App\Models;

use Database\Factories\OfflineCommandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property array<string, mixed> $payload
 * @property Carbon|null $override_expires_at
 * @property Carbon|null $override_used_at
 * @property Carbon|null $applied_at
 * @property Carbon|null $updated_at
 * @property string|null $override_token_hash
 * @property string|null $reason
 */
#[Fillable([
    'tenant_id',
    'branch_id',
    'device_id',
    'command_uuid',
    'command_type',
    'payload',
    'status',
    'reason',
    'override_token_hash',
    'override_expires_at',
    'override_used_at',
    'applied_at',
])]
class OfflineCommand extends Model
{
    /** @use HasFactory<OfflineCommandFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'override_expires_at' => 'datetime',
            'override_used_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    /** @return HasOne<OfflineProvisionalStay, $this> */
    public function provisionalStay(): HasOne
    {
        return $this->hasOne(OfflineProvisionalStay::class);
    }
}
