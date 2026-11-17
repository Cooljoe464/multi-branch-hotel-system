<?php

namespace App\Models;

use Database\Factories\EdgeAgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Hotel-local edge agent credential and protocol heartbeat.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $branch_id
 * @property string $name
 * @property string $agent_id
 * @property string $token_hash
 * @property string $protocol_version
 * @property string $status
 * @property Carbon|null $last_seen_at
 * @property array<string, mixed>|null $metadata
 */
#[Fillable(['tenant_id', 'branch_id', 'name', 'agent_id', 'token_hash', 'protocol_version', 'status', 'last_seen_at', 'metadata'])]
class EdgeAgent extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    /** @use HasFactory<EdgeAgentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'metadata' => 'array'];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
