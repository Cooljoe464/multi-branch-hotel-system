<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $first_name
 * @property string $last_name
 * @property string $full_name
 * @property string $email
 * @property string|null $phone
 * @property Carbon|null $date_of_birth
 * @property string|null $nationality
 * @property string|null $id_type
 * @property string|null $id_number
 * @property string|null $company
 * @property string|null $job_title
 * @property string $vip_status
 * @property int $total_stays
 * @property int $total_nights
 * @property int $total_spent
 * @property string $currency_code
 * @property string $preferred_language
 * @property string $preferred_currency
 * @property string|null $dietary_restrictions
 * @property string|null $special_notes
 * @property Carbon|null $last_stayed_at
 * @property Carbon|null $created_at
 */
class GuestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'nationality' => $this->nationality,
            'id_type' => $this->id_type,
            'id_number' => $this->id_number,
            'company' => $this->company,
            'job_title' => $this->job_title,
            'vip_status' => $this->vip_status,
            'total_stays' => $this->total_stays,
            'total_nights' => $this->total_nights,
            'total_spent' => $this->total_spent,
            'currency_code' => $this->currency_code,
            'preferred_language' => $this->preferred_language,
            'preferred_currency' => $this->preferred_currency,
            'dietary_restrictions' => $this->dietary_restrictions,
            'special_notes' => $this->special_notes,
            'last_stayed_at' => $this->last_stayed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
