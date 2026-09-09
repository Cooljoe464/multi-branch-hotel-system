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
            'reservations' => ['view', 'create', 'update', 'checkin', 'checkout', 'cancel'],
            'rooms' => ['view', 'update_status', 'manage'],
            'guests' => ['view', 'create', 'update'],
            'reports' => ['view', 'export'],
            'settings' => ['view', 'update'],
            'audit' => ['view'],
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
            'reports.view',
            'reports.export',
            'settings.view',
        ]);

        $branchGm = Role::firstOrCreate(
            ['name' => 'Branch GM', 'guard_name' => 'web']
        );
        $branchGm->syncPermissions([
            'branches.view',
            'users.view',
            'users.create',
            'users.update',
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
            'reports.view',
            'reports.export',
            'settings.view',
            'audit.view',
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
        ]);

        $housekeeper = Role::firstOrCreate(
            ['name' => 'Housekeeper', 'guard_name' => 'web']
        );
        $housekeeper->syncPermissions([
            'rooms.view',
            'rooms.update_status',
        ]);
    }
}
