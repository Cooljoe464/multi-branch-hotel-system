<?php

namespace App\Models;

use Database\Factories\ConciergeKbEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Branch knowledge base entry (hours, Wi-Fi, policies, menus). The
 * concierge only answers from these rows; no entry means no grounded
 * answer and the thread goes to a human.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $topic
 * @property string|null $question
 * @property string $answer
 * @property array<int, string>|null $keywords
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 */
#[Fillable([
    'branch_id',
    'topic',
    'question',
    'answer',
    'keywords',
    'active',
])]
class ConciergeKbEntry extends Model
{
    /** @use HasFactory<ConciergeKbEntryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
