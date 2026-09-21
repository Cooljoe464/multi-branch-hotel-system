<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $branch_id
 * @property string|null $exemptable_type
 * @property int|null $exemptable_id
 * @property string $component_code
 * @property string $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 */
#[Fillable([
    'branch_id',
    'exemptable_type',
    'exemptable_id',
    'component_code',
    'reason',
])]
class TaxExemption extends Model
{
    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return MorphTo<Model, $this> */
    public function exemptable(): MorphTo
    {
        return $this->morphTo();
    }
}
