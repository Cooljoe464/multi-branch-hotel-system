<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property int $base_rate
 * @property int $max_occupancy
 * @property int $bed_count
 * @property string $bed_type
 * @property bool $is_active
 * @property array<int, string>|null $amenities
 * @property Carbon|null $created_at
 */
class RoomTypeResource extends JsonResource
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
            'description' => $this->description,
            'base_rate' => $this->base_rate,
            'max_occupancy' => $this->max_occupancy,
            'bed_count' => $this->bed_count,
            'bed_type' => $this->bed_type,
            'is_active' => $this->is_active,
            'amenities' => $this->amenities,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
