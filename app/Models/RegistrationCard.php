<?php

namespace App\Models;

use Database\Factories\RegistrationCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $reservation_id
 * @property string $guest_name
 * @property string $id_type
 * @property string $id_number
 * @property string|null $id_image_url
 * @property string|null $signature_image_url
 * @property Carbon|null $signed_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Reservation $reservation
 */
#[Fillable([
    'reservation_id',
    'guest_name',
    'id_type',
    'id_number',
    'id_image_url',
    'signature_image_url',
    'signed_at',
    'metadata',
])]
class RegistrationCard extends Model
{
    /** @use HasFactory<RegistrationCardFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
