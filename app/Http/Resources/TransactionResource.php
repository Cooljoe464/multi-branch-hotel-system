<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $type
 * @property string $category
 * @property string $description
 * @property int $amount
 * @property bool $is_taxable
 * @property int $tax_amount
 * @property bool $is_voided
 * @property Carbon|null $voided_at
 * @property int|null $posted_by
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property Carbon|null $created_at
 */
class TransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'category' => $this->category,
            'description' => $this->description,
            'amount' => $this->amount,
            'is_taxable' => $this->is_taxable,
            'tax_amount' => $this->tax_amount,
            'is_voided' => $this->is_voided,
            'voided_at' => $this->voided_at?->toIso8601String(),
            'posted_by' => $this->posted_by,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
