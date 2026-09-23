<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Nightly warehouse snapshot manifest. One row per business date;
 * re-exports overwrite the same R2 keys, so checksums are stable
 * and files never duplicate.
 *
 * @property int $id
 * @property Carbon $business_date
 * @property array<string, string>|null $files
 * @property array<string, int>|null $row_counts
 * @property array<string, string>|null $checksums
 * @property string $status
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'business_date',
    'files',
    'row_counts',
    'checksums',
    'status',
    'last_error',
])]
class WarehouseManifest extends Model
{
    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'files' => 'array',
            'row_counts' => 'array',
            'checksums' => 'array',
        ];
    }
}
