<?php

namespace App\Models;

use Database\Factories\InventoryHoldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $expires_at
 * @property Carbon $check_in
 * @property Carbon $check_out
 * @property-read ApiKey|null $apiKey
 */
#[Fillable([
    'tenant_id',
    'branch_id',
    'room_type_id',
    'api_key_id',
    'public_api_quote_id',
    'source',
    'check_in',
    'check_out',
    'status',
    'session_id',
    'client_ip',
    'expires_at',
    'released_at',
])]
class InventoryHold extends Model
{
    /** @use HasFactory<InventoryHoldFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'check_in' => 'date',
            'check_out' => 'date',
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ApiKey, $this> */
    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    /** @return BelongsTo<PublicApiQuote, $this> */
    public function publicApiQuote(): BelongsTo
    {
        return $this->belongsTo(PublicApiQuote::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function active(): bool
    {
        return $this->status === 'active' && $this->expires_at->isFuture();
    }
}
