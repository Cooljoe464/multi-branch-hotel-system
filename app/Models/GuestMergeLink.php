<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Audit trail of a guest merge: retired profile folds into the
 * survivor. Retired rows keep master_guest_id for redirects.
 *
 * @property int $id
 * @property int $surviving_guest_id
 * @property int $retired_guest_id
 * @property int $merged_by
 * @property array<string, mixed>|null $field_choices
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Guest $survivor
 * @property-read Guest $retired
 */
#[Fillable([
    'surviving_guest_id',
    'retired_guest_id',
    'merged_by',
    'field_choices',
])]
class GuestMergeLink extends Model
{
    protected function casts(): array
    {
        return [
            'field_choices' => 'array',
        ];
    }

    /** @return BelongsTo<Guest, $this> */
    public function survivor(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'surviving_guest_id');
    }

    /** @return BelongsTo<Guest, $this> */
    public function retired(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'retired_guest_id');
    }
}
