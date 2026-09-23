<?php

namespace App\Http\Resources;

use App\Models\Reservation;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $folio_number
 * @property string $type
 * @property string $status
 * @property string|null $description
 * @property int $balance
 * @property int $debits_total
 * @property int $credits_total
 * @property int $outstanding_balance
 * @property bool $is_settled
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Reservation|null $reservation
 * @property Collection<int, Transaction> $transactions
 *
 * @method bool relationLoaded(string $key)
 */
class FolioResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'folio_number' => $this->folio_number,
            'type' => $this->type,
            'status' => $this->status,
            'description' => $this->description,
            'balance' => $this->balance,
            'debits_total' => $this->debits_total,
            'credits_total' => $this->credits_total,
            'outstanding_balance' => $this->outstanding_balance,
            'is_settled' => $this->is_settled,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'reservation' => $this->relationLoaded('reservation') ? ReservationResource::make($this->reservation) : null,
            'transactions' => TransactionResource::collection($this->whenLoaded('transactions')),
        ];
    }
}
