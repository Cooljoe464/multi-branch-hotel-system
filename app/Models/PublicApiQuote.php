<?php

namespace App\Models;

use Database\Factories\PublicApiQuoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $tenant_id
 * @property int $branch_id
 * @property int $api_key_id
 * @property int $room_type_id
 * @property int $rate_plan_id
 * @property string $quote_hash
 * @property string $check_in
 * @property string $check_out
 * @property string $currency_code
 * @property int $subtotal_minor
 * @property int $tax_total_minor
 * @property int $total_minor
 * @property array<string, mixed> $rate_snapshot
 * @property array<string, mixed> $tax_snapshot
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 */
#[Fillable([
    'tenant_id', 'branch_id', 'api_key_id', 'room_type_id', 'rate_plan_id', 'quote_hash',
    'check_in', 'check_out', 'currency_code', 'subtotal_minor', 'tax_total_minor', 'total_minor',
    'rate_snapshot', 'tax_snapshot', 'expires_at', 'consumed_at',
])]
class PublicApiQuote extends Model
{
    /** @use HasFactory<PublicApiQuoteFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'tax_total_minor' => 'integer',
            'total_minor' => 'integer',
            'rate_snapshot' => 'array',
            'tax_snapshot' => 'array',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ApiKey, $this> */
    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
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

    /** @return BelongsTo<RatePlan, $this> */
    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    public function usable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}
