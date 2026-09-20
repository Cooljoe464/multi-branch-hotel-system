<?php

namespace Database\Seeders;

use App\Models\KotItem;
use App\Models\PosCharge;
use Illuminate\Database\Seeder;

class KotItemSeeder extends Seeder
{
    public function run(): void
    {
        $posCharges = PosCharge::all();

        if ($posCharges->isEmpty()) {
            return;
        }

        foreach ($posCharges as $posCharge) {
            $this->createKotItems($posCharge);
        }
    }

    private function createKotItems(PosCharge $posCharge): void
    {
        $items = $posCharge->items ?? [];
        $statuses = ['pending', 'preparing', 'ready', 'served'];
        $priorities = ['normal', 'normal', 'rush'];

        foreach ($items as $item) {
            for ($i = 0; $i < ($item['quantity'] ?? 1); $i++) {
                $status = $statuses[array_rand($statuses)];
                $priority = $priorities[array_rand($priorities)];

                KotItem::firstOrCreate(
                    [
                        'pos_charge_id' => $posCharge->id,
                        'item_name' => $item['name'],
                        'quantity' => 1,
                    ],
                    [
                        'branch_id' => $posCharge->branch_id,
                        'outlet' => $posCharge->outlet,
                        'status' => $status,
                        'priority' => $priority,
                        'notes' => $priority === 'rush' ? 'RUSH ORDER' : null,
                        'prepared_at' => in_array($status, ['ready', 'served']) ? now()->subMinutes(rand(5, 30)) : null,
                        'served_at' => $status === 'served' ? now()->subMinutes(rand(1, 10)) : null,
                    ]
                );
            }
        }
    }
}
