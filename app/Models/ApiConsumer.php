<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * External API integrator. Sanctum tokens are issued against the
 * consumer itself (not a user), carrying the consumer scopes plus
 * one `branch:{id}` ability per reachable property. Webhook
 * secrets rotate with dual-secret grace.
 *
 * @property int $id
 * @property string $name
 * @property int|null $branch_id
 * @property list<string>|null $scopes
 * @property list<int>|null $branch_ids
 * @property string|null $webhook_url
 * @property string|null $webhook_secret
 * @property string|null $webhook_previous_secret
 * @property Carbon|null $webhook_grace_until
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch|null $branch
 */
#[Fillable([
    'name',
    'branch_id',
    'scopes',
    'branch_ids',
    'webhook_url',
    'webhook_secret',
    'webhook_previous_secret',
    'webhook_grace_until',
    'is_active',
])]
class ApiConsumer extends Model
{
    use HasApiTokens;

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'branch_ids' => 'array',
            'webhook_grace_until' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return list<string>
     */
    public function tokenAbilities(): array
    {
        $abilities = is_array($this->scopes) ? array_values(array_filter($this->scopes, is_string(...))) : [];

        foreach ($this->reachableBranchIds() as $branchId) {
            $abilities[] = "branch:{$branchId}";
        }

        return array_values(array_unique($abilities));
    }

    /**
     * @return list<int>
     */
    public function reachableBranchIds(): array
    {
        if ($this->branch_id !== null) {
            return [$this->branch_id];
        }

        $ids = is_array($this->branch_ids) ? array_values(array_filter($this->branch_ids, is_int(...))) : [];

        return array_values(array_unique($ids));
    }

    /**
     * @return list<string>
     */
    public function webhookSecrets(): array
    {
        $secrets = [];

        if (is_string($this->webhook_secret) && $this->webhook_secret !== '') {
            $secrets[] = $this->webhook_secret;
        }

        if (is_string($this->webhook_previous_secret)
            && $this->webhook_previous_secret !== ''
            && $this->webhook_grace_until !== null
            && $this->webhook_grace_until->isFuture()
        ) {
            $secrets[] = $this->webhook_previous_secret;
        }

        return $secrets;
    }
}
