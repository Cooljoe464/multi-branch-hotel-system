<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $branch_id
 * @property Carbon $usage_date
 * @property string $metric
 * @property int $count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['tenant_id', 'branch_id', 'usage_date', 'metric', 'count'])]
class TenantUsageDaily extends Model
{
    protected $table = 'tenant_usage_daily';

    use HasFactory;

    protected function casts(): array
    {
        return [
            'usage_date' => 'date',
            'count' => 'integer',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
