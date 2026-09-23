<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\KitchenWasteLog;

class KitchenWasteService
{
    public function logWaste(
        int $branchId,
        int $menuItemId,
        string $reason,
        int $quantity,
        int $cost,
        ?int $loggedBy = null,
        ?string $notes = null,
        ?int $kotItemId = null,
    ): KitchenWasteLog {
        return KitchenWasteLog::create([
            'branch_id' => $branchId,
            'currency_code' => Branch::where('id', $branchId)->value('currency_code') ?? 'NGN',
            'menu_item_id' => $menuItemId,
            'kot_item_id' => $kotItemId,
            'reason' => $reason,
            'quantity' => $quantity,
            'cost' => $cost,
            'notes' => $notes,
            'logged_by' => $loggedBy,
        ]);
    }
}
