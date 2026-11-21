<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $branch_id
 * @property int|null $reservation_id
 * @property int|null $guest_id
 * @property string $guest_name
 * @property int $spa_service_id
 * @property int|null $spa_treatment_room_id
 * @property int|null $spa_therapist_id
 * @property Carbon $scheduled_at
 * @property int $duration_minutes
 * @property int $price_minor
 * @property string $status
 * @property int|null $folio_id
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read Reservation|null $reservation
 * @property-read Guest|null $guest
 * @property-read SpaService $service
 * @property-read SpaTreatmentRoom|null $treatmentRoom
 * @property-read SpaTherapist|null $therapist
 * @property-read Folio|null $folio
 */
#[Fillable([
    'branch_id',
    'reservation_id',
    'guest_id',
    'guest_name',
    'spa_service_id',
    'spa_treatment_room_id',
    'spa_therapist_id',
    'scheduled_at',
    'duration_minutes',
    'price_minor',
    'status',
    'folio_id',
    'notes',
])]
class SpaAppointment extends Model
{
    use HasFactory;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_NO_SHOW = 'no_show';

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'duration_minutes' => 'integer',
            'price_minor' => 'integer',
        ];
    }

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

    /** @return BelongsTo<Guest, $this> */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /** @return BelongsTo<SpaService, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(SpaService::class, 'spa_service_id');
    }

    /** @return BelongsTo<SpaTreatmentRoom, $this> */
    public function treatmentRoom(): BelongsTo
    {
        return $this->belongsTo(SpaTreatmentRoom::class, 'spa_treatment_room_id');
    }

    /** @return BelongsTo<SpaTherapist, $this> */
    public function therapist(): BelongsTo
    {
        return $this->belongsTo(SpaTherapist::class, 'spa_therapist_id');
    }

    /** @return BelongsTo<Folio, $this> */
    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SCHEDULED);
    }
}
