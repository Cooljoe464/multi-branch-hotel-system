<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $branch_id
 * @property int|null $reservation_id
 * @property string $extension
 * @property string $destination
 * @property int $duration_secs
 * @property int $charge_minor
 * @property string $cdr_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read Reservation|null $reservation
 */
#[Fillable([
    'branch_id',
    'reservation_id',
    'extension',
    'destination',
    'duration_secs',
    'charge_minor',
    'cdr_id',
])]
class CallRecord extends Model
{
    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
