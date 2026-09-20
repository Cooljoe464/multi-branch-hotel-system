<?php

namespace App\Http\Resources;

use App\Models\Branch;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $confirmation_number
 * @property string $status
 * @property string $source
 * @property string $guest_name
 * @property string|null $guest_email
 * @property string|null $guest_phone
 * @property int $adults
 * @property int $children
 * @property Carbon|null $check_in_date
 * @property Carbon|null $check_out_date
 * @property int $nights
 * @property Carbon|null $actual_check_in_at
 * @property Carbon|null $actual_check_out_at
 * @property int $room_rate
 * @property int $total_amount
 * @property int $amount_paid
 * @property string $payment_status
 * @property bool $is_group_booking
 * @property string|null $group_id
 * @property array<string, mixed>|null $special_requests
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Branch|null $branch
 * @property Room|null $room
 * @property RoomType|null $roomType
 * @property Guest|null $guest
 * @property Folio|null $folio
 *
 * @method bool relationLoaded(string $key)
 */
class ReservationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'confirmation_number' => $this->confirmation_number,
            'status' => $this->status,
            'source' => $this->source,
            'guest_name' => $this->guest_name,
            'guest_email' => $this->guest_email,
            'guest_phone' => $this->guest_phone,
            'adults' => $this->adults,
            'children' => $this->children,
            'check_in_date' => $this->check_in_date?->toDateString(),
            'check_out_date' => $this->check_out_date?->toDateString(),
            'nights' => $this->nights,
            'actual_check_in_at' => $this->actual_check_in_at?->toIso8601String(),
            'actual_check_out_at' => $this->actual_check_out_at?->toIso8601String(),
            'room_rate' => $this->room_rate,
            'total_amount' => $this->total_amount,
            'amount_paid' => $this->amount_paid,
            'payment_status' => $this->payment_status,
            'is_group_booking' => $this->is_group_booking,
            'group_id' => $this->group_id,
            'special_requests' => $this->special_requests,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'branch' => $this->relationLoaded('branch') ? BranchResource::make($this->branch) : null,
            'room' => $this->relationLoaded('room') ? RoomResource::make($this->room) : null,
            'room_type' => $this->relationLoaded('roomType') ? RoomTypeResource::make($this->roomType) : null,
            'guest' => $this->relationLoaded('guest') ? GuestResource::make($this->guest) : null,
            'folio' => $this->relationLoaded('folio') ? FolioResource::make($this->folio) : null,
        ];
    }
}
