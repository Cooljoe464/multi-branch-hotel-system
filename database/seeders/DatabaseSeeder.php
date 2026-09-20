<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            BrandingSeeder::class,
            RoleSeeder::class,
            AdminSeeder::class,
            RoomTypeSeeder::class,
            RoomSeeder::class,
            GuestSeeder::class,
            GuestPreferenceSeeder::class,
            ReservationSeeder::class,
            TaskSeeder::class,
            MaintenanceTicketSeeder::class,
            FolioSeeder::class,
            TransactionSeeder::class,
            PosChargeSeeder::class,
            KotItemSeeder::class,
            DailyLedgerSeeder::class,
            PaymentTransactionSeeder::class,
            DoorLockGatewaySeeder::class,
            DoorLockAuditLogSeeder::class,
            RateOverrideSeeder::class,
            YieldRuleSeeder::class,
            RatePlanSeeder::class,
            OutletSeeder::class,
            InventorySeeder::class,
            MenuItemSeeder::class,
            MenuItemStationSeeder::class,
            RecipeSeeder::class,
            BankProfileSeeder::class,
            GroupLedgerSeeder::class,
            TransferRequestSeeder::class,
            AuditFlagSeeder::class,
        ]);
    }
}
