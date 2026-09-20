<?php

namespace App\Models;

use Database\Factories\AuditFlagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $branch_id
 * @property string $flag_type
 * @property string $severity
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string $description
 * @property array<string, mixed>|null $evidence
 * @property bool $is_reviewed
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read User|null $reviewer
 */
#[Fillable(['branch_id', 'flag_type', 'severity', 'subject_type', 'subject_id', 'description', 'evidence', 'is_reviewed', 'reviewed_by', 'reviewed_at', 'metadata'])]
class AuditFlag extends Model
{
    /** @use HasFactory<AuditFlagFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'is_reviewed' => 'boolean',
            'reviewed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
