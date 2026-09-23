<?php

namespace App\Http\Resources;

use App\Models\Folio;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Stable v1 folio shape with live transaction lines.
 *
 * @property int $id
 * @property string $folio_number
 * @property int $branch_id
 * @property int|null $reservation_id
 * @property string $status
 * @property int $balance
 * @property string|null $currency_code
 * @property Carbon|null $created_at
 * @property Collection<int, Transaction> $transactions
 *
 * @mixin Folio
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
            'branch_id' => $this->branch_id,
            'reservation_id' => $this->reservation_id,
            'status' => $this->status,
            'balance_minor' => $this->balance,
            'currency_code' => $this->currency_code,
            'transactions' => $this->transactions
                ->where('is_voided', false)
                ->values()
                ->map(fn (Transaction $t) => [
                    'id' => $t->id,
                    'type' => $t->type,
                    'category' => $t->category,
                    'description' => $t->description,
                    'amount_minor' => $t->amount,
                    'posted_at' => $t->created_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }
}
