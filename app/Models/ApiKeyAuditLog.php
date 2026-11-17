<?php

namespace App\Models;

use Database\Factories\ApiKeyAuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $branch_id
 * @property int $api_key_id
 * @property int|null $actor_id
 * @property string $event
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 */
#[Fillable(['tenant_id', 'branch_id', 'api_key_id', 'actor_id', 'event', 'metadata'])]
class ApiKeyAuditLog extends Model
{
    /** @use HasFactory<ApiKeyAuditLogFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /** @return BelongsTo<ApiKey, $this> */
    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }
}
