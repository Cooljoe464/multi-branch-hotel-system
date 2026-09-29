<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Hash-only log: prompt and SQL fingerprints plus performance,
 * never result rows (which may carry masked PII).
 *
 * @property int $id
 * @property int $branch_id
 * @property int|null $user_id
 * @property string $query_key
 * @property string $prompt_hash
 * @property string $sql_hash
 * @property string $status
 * @property int $rows
 * @property int $duration_ms
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 */
#[Fillable([
    'branch_id',
    'user_id',
    'query_key',
    'prompt_hash',
    'sql_hash',
    'status',
    'rows',
    'duration_ms',
    'last_error',
])]
class NlQueryLog extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
