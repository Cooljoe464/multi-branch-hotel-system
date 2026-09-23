<?php

namespace App\Http\Resources;

use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Stable v1 reservation shape. Money stays integer minor units with
 * an explicit currency_code; new fields only ever append.
 *
 * @property int $id
 * @property string $confirmation_number
 * @property int $branch_id
 * @property string $status
 * @property string $guest_name
 * @property string|null $guest_email
 * @property int $adults
 * @property int $children
 * @property Carbon $check_in_date
 * @property Carbon $check_out_date
 * @property int $room_type_id
 * @property int|null $room_id
 * @property int $room_rate
 * @property int $total_amount
 * @property int $amount_paid
 * @property string|null $currency_code
 * @property string $source
 * @property string $payment_status
 * @property string $guarantee_status
 * @property Carbon|null $cancel_deadline_at
 *
 * @mixin Reservation
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
            'branch_id' => $this->branch_id,
            'status' => $this->status,
            'guest_name' => $this->guest_name,
            'guest_email' => $this->guest_email,
            'adults' => $this->adults,
            'children' => $this->children,
            'check_in_date' => $this->check_in_date->toDateString(),
            'check_out_date' => $this->check_out_date->toDateString(),
            'room_type_id' => $this->room_type_id,
            'room_id' => $this->room_id,
            'room_rate_minor' => $this->room_rate,
            'total_minor' => $this->total_amount,
            'amount_paid_minor' => $this->amount_paid,
            'currency_code' => $this->currency_code,
            'source' => $this->source,
            'payment_status' => $this->payment_status,
            'guarantee_status' => $this->guarantee_status,
            'cancel_deadline_at' => $this->cancel_deadline_at?->toIso8601String(),
        ];
    }
}
