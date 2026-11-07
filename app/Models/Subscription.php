<?php

namespace App\Models;

use App\Services\EntitlementService;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $plan_id
 * @property string $status
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $current_period_ends_at
 * @property Carbon|null $past_due_at
 * @property Carbon|null $grace_ends_at
 * @property Carbon|null $restricted_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Plan $plan
 */
#[Fillable([
    'tenant_id', 'plan_id', 'status', 'trial_ends_at', 'current_period_ends_at',
    'past_due_at', 'grace_ends_at', 'restricted_at', 'cancelled_at',
])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    public const STATUS_TRIALING = 'trialing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_GRACE = 'grace';

    public const STATUS_RESTRICTED = 'restricted';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * States in which the tenant keeps full plan entitlements.
     *
     * @var list<string>
     */
    public const FULL_ACCESS_STATUSES = [
        self::STATUS_TRIALING,
        self::STATUS_ACTIVE,
        self::STATUS_PAST_DUE,
        self::STATUS_GRACE,
    ];

    protected static function booted(): void
    {
        $flush = static fn () => app(EntitlementService::class)->flush();

        static::saved($flush);
        static::deleted($flush);
    }

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'past_due_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'restricted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function hasFullAccess(): bool
    {
        return in_array($this->status, self::FULL_ACCESS_STATUSES, true);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
