<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * Identity document with encrypted number (APP_KEY envelope; per-branch
 * key-wrap policy lives in config/gdpr.php). Scans are private R2
 * objects served via signed URLs only — never logged, never public.
 *
 * @property int $id
 * @property int $guest_id
 * @property string $doc_type
 * @property string|null $doc_number
 * @property string|null $scan_path
 * @property array<string, mixed>|null $ocr_result
 * @property string $ocr_status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Guest $guest
 */
#[Fillable([
    'guest_id',
    'doc_type',
    'doc_number',
    'scan_path',
    'ocr_result',
    'ocr_status',
])]
class GuestIdentityDocument extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DONE = 'done';

    public const STATUS_MANUAL = 'manual';

    protected function casts(): array
    {
        return [
            'ocr_result' => 'array',
        ];
    }

    /**
     * Encrypted accessor pair over the doc_number_enc column: the
     * database only ever holds ciphertext.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function docNumber(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value, array $attributes): ?string {
                $raw = $attributes['doc_number_enc'] ?? null;

                if (! is_string($raw) || $raw === '') {
                    return null;
                }

                return Crypt::decryptString($raw);
            },
            set: function (mixed $value): array {
                if (! is_string($value) || $value === '') {
                    return ['doc_number_enc' => null];
                }

                return ['doc_number_enc' => Crypt::encryptString($value)];
            },
        );
    }

    /** @return BelongsTo<Guest, $this> */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
