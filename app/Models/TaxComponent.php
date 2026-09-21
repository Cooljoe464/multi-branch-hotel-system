<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One tax/levy line inside a profile. Rates are basis points; math is
 * integer-only in TaxService.
 *
 * @property int $id
 * @property int $tax_profile_id
 * @property string $code
 * @property string $mode
 * @property int $rate_bps
 * @property string $applies_to
 * @property int $sequence
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TaxProfile $profile
 */
#[Fillable([
    'tax_profile_id',
    'code',
    'mode',
    'rate_bps',
    'applies_to',
    'sequence',
])]
class TaxComponent extends Model
{
    public const MODE_EXCLUSIVE = 'exclusive';

    public const MODE_INCLUSIVE = 'inclusive';

    protected function casts(): array
    {
        return [
            'rate_bps' => 'integer',
            'sequence' => 'integer',
        ];
    }

    /** @return BelongsTo<TaxProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(TaxProfile::class, 'tax_profile_id');
    }
}
