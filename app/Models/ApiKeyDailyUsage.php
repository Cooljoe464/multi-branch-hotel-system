<?php

namespace App\Models;

use Database\Factories\ApiKeyDailyUsageFactory;
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
 * @property Carbon $usage_date
 * @property int $request_count
 */
#[Fillable(['tenant_id', 'branch_id', 'api_key_id', 'usage_date', 'request_count'])]
class ApiKeyDailyUsage extends Model
{
    protected $table = 'api_key_daily_usage';

    /** @use HasFactory<ApiKeyDailyUsageFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'usage_date' => 'date',
            'request_count' => 'integer',
        ];
    }

    /** @return BelongsTo<ApiKey, $this> */
    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }
}
