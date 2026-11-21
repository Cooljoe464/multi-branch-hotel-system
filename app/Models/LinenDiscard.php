<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $branch_id
 * @property int $linen_item_id
 * @property int $quantity
 * @property string $reason
 * @property int $unit_cost_minor
 * @property int $total_loss_minor
 * @property int $approved_by
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read LinenItem $linenItem
 * @property-read User $approver
 */
#[Fillable([
    'branch_id',
    'linen_item_id',
    'quantity',
    'reason',
    'unit_cost_minor',
    'total_loss_minor',
    'approved_by',
    'notes',
])]
class LinenDiscard extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost_minor' => 'integer',
            'total_loss_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<LinenItem, $this> */
    public function linenItem(): BelongsTo
    {
        return $this->belongsTo(LinenItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
