<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->createPermissions();

        $this->createRoles();
    }

    private function createPermissions(): void
    {
        $groups = [
            'branches' => ['view', 'manage'],
            'users' => ['view', 'create', 'update', 'delete'],
            'roles' => ['view', 'assign'],
            'reservations' => ['view', 'create', 'update', 'checkin', 'checkout', 'cancel', 'delete'],
            'rooms' => ['view', 'update_status', 'manage'],
            'guests' => ['view', 'create', 'update', 'manage'],
            'reports' => ['view', 'export'],
            'settings' => ['view', 'update', 'manage'],
            'audit' => ['view', 'manage'],
            'yield_rules' => ['view', 'manage'],
            'rate_overrides' => ['view', 'manage'],
            'door_lock' => ['view', 'manage'],
            'tape_chart' => ['view'],
            'housekeeping' => ['view', 'manage'],
            'maintenance' => ['view', 'manage'],
            'folios' => ['view', 'manage'],
            'analytics' => ['view', 'manage'],
            'menu_items' => ['view', 'manage'],
            'rate_plans' => ['view', 'manage'],
            'outlets' => ['view', 'manage'],
            'inventory' => ['view', 'manage'],
            'transfers' => ['view', 'manage'],
            'channels' => ['view', 'manage'],
            'crs' => ['view', 'manage'],
            'city_ledger' => ['view', 'manage'],
            'laundry' => ['view', 'manage'],
            'pos' => ['view', 'manage'],
            'kds' => ['view', 'manage'],
            'business_date' => ['view', 'close'],
        ];

        foreach ($groups as $group => $actions) {
            foreach ($actions as $action) {
                Permission::firstOrCreate([
                    'name' => "{$group}.{$action}",
                    'guard_name' => 'web',
                ]);
            }
        }
    }

    private function createRoles(): void
    {
        $globalAdmin = Role::firstOrCreate(
            ['name' => 'Global Admin', 'guard_name' => 'web']
        );
        $globalAdmin->syncPermissions(Permission::all());

        $propertyOwner = Role::firstOrCreate(
            ['name' => 'Property Owner', 'guard_name' => 'web']
        );
        $propertyOwner->syncPermissions([
            'branches.view',
            'users.view',
            'users.create',
            'users.update',
            'reservations.view',
            'reservations.create',
            'rooms.view',
            'guests.view',
            'tape_chart.view',
            'housekeeping.view',
            'maintenance.view',
            'folios.view',
            'analytics.view',
            'analytics.manage',
            'reports.view',
            'reports.export',
            'settings.view',
            'yield_rules.view',
            'yield_rules.manage',
            'rate_overrides.view',
            'rate_overrides.manage',
            'door_lock.view',
            'door_lock.manage',
            'menu_items.view',
            'menu_items.manage',
            'rate_plans.view',
            'rate_plans.manage',
            'outlets.view',
            'outlets.manage',
            'inventory.view',
            'inventory.manage',
            'transfers.view',
            'transfers.manage',
            'channels.view',
            'channels.manage',
            'crs.view',
            'crs.manage',
            'city_ledger.view',
            'city_ledger.manage',
            'laundry.view',
            'laundry.manage',
            'pos.view',
            'pos.manage',
            'kds.view',
            'kds.manage',
            'business_date.view',
        ]);

        $branchGm = Role::firstOrCreate(
            ['name' => 'Branch GM', 'guard_name' => 'web']
        );
        $branchGm->syncPermissions([
            'branches.view',
            'business_date.view',
            'business_date.close',
            'users.view',
            'users.create',
            'users.update',
            'reservations.view',
            'reservations.create',
            'reservations.update',
            'reservations.checkin',
            'reservations.checkout',
            'reservations.cancel',
            'rooms.view',
            'rooms.update_status',
            'guests.view',
            'guests.create',
            'guests.update',
            'tape_chart.view',
            'housekeeping.view',
            'housekeeping.manage',
            'maintenance.view',
            'maintenance.manage',
            'folios.view',
            'folios.manage',
            'analytics.view',
            'reports.view',
            'reports.export',
            'settings.view',
            'audit.view',
            'yield_rules.view',
            'yield_rules.manage',
            'rate_overrides.view',
            'rate_overrides.manage',
            'door_lock.view',
            'door_lock.manage',
            'menu_items.view',
            'menu_items.manage',
            'rate_plans.view',
            'rate_plans.manage',
            'outlets.view',
            'outlets.manage',
            'inventory.view',
            'inventory.manage',
            'transfers.view',
            'transfers.manage',
            'laundry.view',
            'laundry.manage',
            'pos.view',
            'pos.manage',
            'kds.view',
            'kds.manage',
        ]);

        $frontDesk = Role::firstOrCreate(
            ['name' => 'Front Desk', 'guard_name' => 'web']
        );
        $frontDesk->syncPermissions([
            'reservations.view',
            'reservations.create',
            'reservations.update',
            'reservations.checkin',
            'reservations.checkout',
            'rooms.view',
            'rooms.update_status',
            'guests.view',
            'guests.create',
            'guests.update',
            'tape_chart.view',
            'housekeeping.view',
            'maintenance.view',
            'folios.view',
            'folios.manage',
            'menu_items.view',
            'inventory.view',
            'pos.view',
            'pos.manage',
            'laundry.view',
            'laundry.manage',
            'business_date.view',
        ]);

        $housekeeper = Role::firstOrCreate(
            ['name' => 'Housekeeper', 'guard_name' => 'web']
        );
        $housekeeper->syncPermissions([
            'rooms.view',
            'rooms.update_status',
            'housekeeping.view',
            'housekeeping.manage',
        ]);

        $kitchenStaff = Role::firstOrCreate(
            ['name' => 'Kitchen Staff', 'guard_name' => 'web']
        );
        $kitchenStaff->syncPermissions([
            'kds.view',
            'kds.manage',
            'menu_items.view',
            'inventory.view',
        ]);

        $laundryAttendant = Role::firstOrCreate(
            ['name' => 'Laundry Attendant', 'guard_name' => 'web']
        );
        $laundryAttendant->syncPermissions([
            'laundry.view',
            'laundry.manage',
            'rooms.view',
        ]);

        $cashier = Role::firstOrCreate(
            ['name' => 'Cashier', 'guard_name' => 'web']
        );
        $cashier->syncPermissions([
            'pos.view',
            'pos.manage',
            'folios.view',
            'folios.manage',
            'reports.view',
            'reports.export',
            'business_date.view',
        ]);

        $auditor = Role::firstOrCreate(
            ['name' => 'Auditor', 'guard_name' => 'web']
        );
        $auditor->syncPermissions([
            'audit.view',
            'audit.manage',
            'reports.view',
            'reports.export',
            'analytics.view',
            'analytics.manage',
            'settings.view',
            'settings.update',
            'settings.manage',
            'business_date.view',
        ]);
    }
}
