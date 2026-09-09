<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $guest_id
 * @property string $category
 * @property string $key
 * @property string $value
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Guest $guest
 */
#[Fillable([
    'guest_id',
    'category',
    'key',
    'value',
    'notes',
])]
class GuestPreference extends Model
{
    use HasFactory;

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
