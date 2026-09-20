<?php

namespace App\Models;

use Database\Factories\ChannelProviderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $branch_id
 * @property string $provider
 * @property string|null $api_key
 * @property string|null $api_secret
 * @property string|null $property_id_external
 * @property bool $is_active
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $last_sync_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Branch $branch
 */
#[Fillable(['branch_id', 'provider', 'api_key', 'api_secret', 'property_id_external', 'is_active', 'settings', 'last_sync_at', 'metadata'])]
class ChannelProviderModel extends Model
{
    /** @use HasFactory<ChannelProviderFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'channel_providers';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
            'last_sync_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<ChannelReservation, $this> */
    public function channelReservations(): HasMany
    {
        return $this->hasMany(ChannelReservation::class, 'channel_provider_id');
    }

    /** @return HasMany<ChannelRate, $this> */
    public function channelRates(): HasMany
    {
        return $this->hasMany(ChannelRate::class, 'channel_provider_id');
    }
}
