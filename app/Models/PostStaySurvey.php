<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One survey per reservation. Re-submits update the same row;
 * sentiment is scored at submit time (heuristic now, model hook
 * later) and low scores with disputes feed audit flags.
 *
 * @property int $id
 * @property int $reservation_id
 * @property int|null $nps
 * @property array<string, mixed>|null $answers
 * @property float|null $sentiment
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Reservation $reservation
 */
#[Fillable([
    'reservation_id',
    'nps',
    'answers',
    'sentiment',
])]
class PostStaySurvey extends Model
{
    protected function casts(): array
    {
        return [
            'nps' => 'integer',
            'answers' => 'array',
            'sentiment' => 'float',
        ];
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
