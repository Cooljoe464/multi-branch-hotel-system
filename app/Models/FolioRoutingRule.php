<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $folio_id
 * @property string $charge_category
 * @property int $target_window_id
 * @property int $priority
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Folio $folio
 * @property-read FolioWindow $targetWindow
 */
#[Fillable([
    'folio_id',
    'charge_category',
    'target_window_id',
    'priority',
    'active',
])]
class FolioRoutingRule extends Model
{
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Folio, $this> */
    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    /** @return BelongsTo<FolioWindow, $this> */
    public function targetWindow(): BelongsTo
    {
        return $this->belongsTo(FolioWindow::class, 'target_window_id');
    }
}
