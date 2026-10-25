<?php

namespace App\Models;

use Database\Factories\BranchSecretFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Encrypted, vendor credential with an optional branch scope. Values
 * are stored as Crypt payloads and only ever surfaced through
 * SecretManager: branch_id NULL means the platform-wide scope used by
 * account-level vendors (OCR, shared webhooks).
 *
 * @property int $id
 * @property int|null $branch_id
 * @property string $key
 * @property string $value
 * @property Carbon|null $rotated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch|null $branch
 *
 * @method static Builder<static> forBranch(int $branchId)
 */
#[Fillable([
    'branch_id',
    'key',
    'value',
    'rotated_at',
])]
class BranchSecret extends Model
{
    /** @use HasFactory<BranchSecretFactory> */
    use HasFactory;

    protected $hidden = ['value'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'rotated_at' => 'datetime',
    ];

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }
}
