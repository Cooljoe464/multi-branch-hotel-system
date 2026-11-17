<?php

namespace App\Models;

use Database\Factories\RelocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'reservation_id',
    'source_branch_id',
    'destination_branch_id',
    'destination_room_id',
    'status',
    'reason',
    'compensation_minor',
    'currency_code',
    'idempotency_key',
    'compensation_transaction_id',
    'created_by',
])]
class Relocation extends Model
{
    /** @use HasFactory<RelocationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['compensation_minor' => 'integer'];
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function sourceBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'source_branch_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function destinationBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'destination_branch_id');
    }

    /** @return BelongsTo<Room, $this> */
    public function destinationRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'destination_room_id');
    }

    /** @return BelongsTo<Transaction, $this> */
    public function compensationTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'compensation_transaction_id');
    }
}
