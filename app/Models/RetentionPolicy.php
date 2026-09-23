<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Retention rule per data class. folio_lines is a legal hold and is
 * never purged — the scheduler skips it by design.
 *
 * @property int $id
 * @property string $data_class
 * @property int $retain_days
 * @property string $action
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'data_class',
    'retain_days',
    'action',
])]
class RetentionPolicy extends Model
{
    public const ACTION_ANONYMIZE = 'anonymize';

    public const ACTION_PURGE = 'purge';

    protected function casts(): array
    {
        return [
            'retain_days' => 'integer',
        ];
    }
}
