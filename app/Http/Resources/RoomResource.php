<?php

namespace App\Http\Resources;

use App\Models\Branch;
use App\Models\Reservation;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $number
 * @property string|null $floor
 * @property string|null $wing
 * @property string $status
 * @property bool $is_accessible
 * @property bool $is_smoking
 * @property bool $is_active
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Branch|null $branch
 * @property RoomType|null $roomType
 * @property Reservation|null $currentReservation
 *
 * @method bool relationLoaded(string $key)
 */
class RoomResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'floor' => $this->floor,
            'wing' => $this->wing,
            'status' => $this->status,
            'is_accessible' => $this->is_accessible,
            'is_smoking' => $this->is_smoking,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'branch' => $this->relationLoaded('branch') ? BranchResource::make($this->branch) : null,
            'room_type' => $this->relationLoaded('roomType') ? RoomTypeResource::make($this->roomType) : null,
            'current_reservation' => $this->relationLoaded('currentReservation') ? ReservationResource::make($this->currentReservation) : null,
        ];
    }
}
