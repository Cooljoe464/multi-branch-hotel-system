<?php

namespace App\Models;

use App\Services\EntitlementService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $plan_id
 * @property string $entitlement_key
 * @property string $value_type
 * @property string $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Plan $plan
 */
#[Fillable(['plan_id', 'entitlement_key', 'value_type', 'value'])]
class PlanEntitlement extends Model
{
    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_INTEGER_LIMIT = 'integer_limit';

    protected static function booted(): void
    {
        $flush = static fn () => app(EntitlementService::class)->flush();

        static::saved($flush);
        static::deleted($flush);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
