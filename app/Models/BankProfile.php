<?php

namespace App\Models;

use Database\Factories\BankProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $branch_id
 * @property string $bank_name
 * @property string $account_number
 * @property string $account_name
 * @property string|null $swift_code
 * @property string|null $sort_code
 * @property string $currency_code
 * @property bool $is_default
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Branch $branch
 */
#[Fillable(['branch_id', 'bank_name', 'account_number', 'account_name', 'swift_code', 'sort_code', 'currency_code', 'is_default', 'metadata'])]
class BankProfile extends Model
{
    /** @use HasFactory<BankProfileFactory> */
    use HasFactory;

    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
