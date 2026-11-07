<?php

namespace App\Models;

use App\Services\EntitlementService;
use Database\Factories\TenantEntitlementOverrideFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A time-limited commercial override of one entitlement for one tenant.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $entitlement_key
 * @property string $value_type
 * @property string $value
 * @property string $reason
 * @property int|null $created_by
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 */
#[Fillable(['tenant_id', 'entitlement_key', 'value_type', 'value', 'reason', 'created_by', 'expires_at'])]
class TenantEntitlementOverride extends Model
{
    /** @use HasFactory<TenantEntitlementOverrideFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        $flush = static fn () => app(EntitlementService::class)->flush();

        static::saved($flush);
        static::deleted($flush);
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
