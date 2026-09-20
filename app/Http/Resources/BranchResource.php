<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $slug
 * @property string|null $address
 * @property string $city
 * @property string|null $state
 * @property string $country
 * @property string|null $postal_code
 * @property string|null $phone
 * @property string|null $email
 * @property string $timezone
 * @property string $currency_code
 * @property string $currency_symbol
 * @property float $tax_rate
 * @property string $tax_label
 * @property bool $is_active
 * @property bool $is_primary
 * @property Carbon|null $created_at
 */
class BranchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'slug' => $this->slug,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'postal_code' => $this->postal_code,
            'phone' => $this->phone,
            'email' => $this->email,
            'timezone' => $this->timezone,
            'currency_code' => $this->currency_code,
            'currency_symbol' => $this->currency_symbol,
            'tax_rate' => $this->tax_rate,
            'tax_label' => $this->tax_label,
            'is_active' => $this->is_active,
            'is_primary' => $this->is_primary,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
