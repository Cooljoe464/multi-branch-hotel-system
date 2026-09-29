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
            'reservations' => ['view', 'create', 'update', 'checkin', 'checkout', 'cancel', 'delete', 'waive_penalty', 'override_guarantee', 'move_room'],
            'rooms' => ['view', 'update_status', 'manage'],
            'guests' => ['view', 'create', 'update', 'manage', 'merge', 'manage_dnr', 'view_pii'],
            'reports' => ['view', 'export'],
            'settings' => ['view', 'update', 'manage'],
            'audit' => ['view', 'manage', 'run_night_audit', 'retry_night_audit', 'manage_anomaly_rules', 'confirm_anomaly'],
            'yield_rules' => ['view', 'manage', 'approve_ai_price'],
            'rate_overrides' => ['view', 'manage'],
            'door_lock' => ['view', 'manage', 'issue_mobile_key'],
            'telecom' => ['view', 'manage_rates'],
            'tape_chart' => ['view'],
            'housekeeping' => ['view', 'manage', 'assign', 'inspect', 'manage_lost_found'],
            'maintenance' => ['view', 'manage', 'manage_assets', 'manage_sla'],
            'folios' => ['view', 'manage', 'split', 'transfer', 'manage_routing'],
            'analytics' => ['view', 'manage', 'export_warehouse'],
            'menu_items' => ['view', 'manage'],
            'rate_plans' => ['view', 'manage', 'approve_ai_price'],
            'outlets' => ['view', 'manage'],
            'inventory' => ['view', 'manage', 'manage_suppliers', 'manage_po_grn', 'view_costing'],
            'transfers' => ['view', 'manage'],
            'channels' => ['view', 'manage', 'manage_mapping', 'replay'],
            'crs' => ['view', 'manage'],
            'city_ledger' => ['view', 'manage'],
            'laundry' => ['view', 'manage'],
            'pos' => ['view', 'manage', 'manage_floor', 'manage_pricing'],
            'kds' => ['view', 'manage'],
            'business_date' => ['view', 'close'],
            'idempotency' => ['view'],
            'journal' => ['view', 'export'],
            'system_health' => ['view', 'run_dr_drill'],
            'availability' => ['view', 'override_overbook'],
            'tax' => ['view', 'manage'],
            'fiscal' => ['view', 'retry'],
            'accounting' => ['view', 'manage_chart', 'close_period'],
            'payments' => ['charge', 'refund', 'manage_drivers'],
            'cashier' => ['open_shift', 'close_shift', 'approve_void'],
            'commissions' => ['view', 'manage', 'pay'],
            'groups' => ['view', 'manage', 'manage_beo'],
            'privacy' => ['view', 'fulfill', 'set_retention'],
            'statutory' => ['view', 'generate'],
            'crm' => ['view', 'manage', 'redeem'],
            'upsell' => ['view', 'manage', 'grant_free'],
            'rate_restrictions' => ['view', 'manage', 'override'],
            'rate_seasons' => ['view', 'manage'],
            'promo_codes' => ['view', 'manage'],
            'corporate_accounts' => ['view', 'manage'],
            'api' => ['view', 'manage_consumers'],
            'accounting_export' => ['view', 'manage', 'retry'],
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
            'analytics.export_warehouse',
            'api.view',
            'accounting_export.view',
            'reports.view',
            'reports.export',
            'settings.view',
            'yield_rules.view',
            'yield_rules.manage',
            'yield_rules.approve_ai_price',
            'rate_overrides.view',
            'rate_overrides.manage',
            'door_lock.view',
            'door_lock.manage',
            'menu_items.view',
            'menu_items.manage',
            'rate_plans.view',
            'rate_plans.manage',
            'rate_plans.approve_ai_price',
            'outlets.view',
            'outlets.manage',
            'inventory.view',
            'inventory.manage',
            'inventory.view_costing',
            'transfers.view',
            'transfers.manage',
            'channels.view',
            'channels.manage',
            'channels.manage_mapping',
            'channels.replay',
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
            'journal.view',
            'availability.view',
            'accounting.view',
            'commissions.view',
            'commissions.pay',
        ]);

        $branchGm = Role::firstOrCreate(['name' => 'Branch GM', 'guard_name' => 'web']
        );
        $branchGm->syncPermissions([
            'branches.view',
            'business_date.view',
            'business_date.close',
            'availability.view',
            'availability.override_overbook',
            'commissions.view',
            'commissions.manage',
            'commissions.pay',
            'groups.view',
            'groups.manage',
            'groups.manage_beo',
            'tax.view',
            'tax.manage',
            'fiscal.view',
            'fiscal.retry',
            'accounting.view',
            'accounting_export.view',
            'accounting_export.retry',
            'cashier.open_shift',
            'cashier.close_shift',
            'cashier.approve_void',
            'rate_restrictions.view',
            'rate_restrictions.manage',
            'rate_restrictions.override',
            'rate_seasons.view',
            'rate_seasons.manage',
            'promo_codes.view',
            'promo_codes.manage',
            'corporate_accounts.view',
            'corporate_accounts.manage',
            'users.view',
            'users.create',
            'users.update',
            'reservations.view',
            'reservations.create',
            'reservations.update',
            'reservations.checkin',
            'reservations.checkout',
            'reservations.cancel',
            'reservations.waive_penalty',
            'reservations.override_guarantee',
            'reservations.move_room',
            'rooms.view',
            'rooms.update_status',
            'guests.view',
            'guests.create',
            'guests.update',
            'guests.merge',
            'guests.manage_dnr',
            'guests.view_pii',
            'tape_chart.view',
            'housekeeping.view',
            'housekeeping.manage',
            'housekeeping.assign',
            'housekeeping.inspect',
            'housekeeping.manage_lost_found',
            'maintenance.view',
            'maintenance.manage',
            'maintenance.manage_assets',
            'maintenance.manage_sla',
            'privacy.view',
            'privacy.fulfill',
            'statutory.view',
            'statutory.generate',
            'crm.view',
            'crm.manage',
            'crm.redeem',
            'upsell.view',
            'upsell.manage',
            'upsell.grant_free',
            'folios.view',
            'folios.manage',
            'folios.split',
            'folios.transfer',
            'door_lock.issue_mobile_key',
            'telecom.view',
            'payments.charge',
            'payments.refund',
            'analytics.view',
            'analytics.manage',
            'api.view',
            'reports.view',
            'reports.export',
            'settings.view',
            'audit.view',
            'audit.confirm_anomaly',
            'audit.run_night_audit',
            'audit.retry_night_audit',
            'yield_rules.view',
            'yield_rules.manage',
            'yield_rules.approve_ai_price',
            'rate_overrides.view',
            'rate_overrides.manage',
            'door_lock.view',
            'door_lock.manage',
            'door_lock.issue_mobile_key',
            'telecom.view',
            'telecom.manage_rates',
            'menu_items.view',
            'menu_items.manage',
            'rate_plans.view',
            'rate_plans.manage',
            'rate_plans.approve_ai_price',
            'outlets.view',
            'outlets.manage',
            'inventory.view',
            'inventory.manage',
            'inventory.manage_suppliers',
            'inventory.manage_po_grn',
            'inventory.view_costing',
            'transfers.view',
            'transfers.manage',
            'laundry.view',
            'laundry.manage',
            'pos.view',
            'pos.manage',
            'pos.manage_floor',
            'pos.manage_pricing',
            'kds.view',
            'kds.manage',
            'idempotency.view',
            'journal.view',
            'system_health.view',
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
            'reservations.move_room',
            'rooms.view',
            'rooms.update_status',
            'guests.view',
            'guests.create',
            'guests.update',
            'guests.view_pii',
            'tape_chart.view',
            'housekeeping.view',
            'housekeeping.assign',
            'maintenance.view',
            'folios.view',
            'folios.manage',
            'folios.split',
            'folios.transfer',
            'payments.charge',
            'menu_items.view',
            'inventory.view',
            'pos.view',
            'pos.manage',
            'laundry.view',
            'laundry.manage',
            'business_date.view',
            'availability.view',
            'analytics.view',
            'channels.replay',
            'groups.view',
            'groups.manage',
            'statutory.view',
            'crm.view',
            'crm.manage',
            'crm.redeem',
            'upsell.view',
            'cashier.open_shift',
            'cashier.close_shift',
            'rate_restrictions.view',
            'rate_seasons.view',
            'promo_codes.view',
            'corporate_accounts.view',
        ]);

        $housekeeper = Role::firstOrCreate(
            ['name' => 'Housekeeper', 'guard_name' => 'web']
        );
        $housekeeper->syncPermissions([
            'rooms.view',
            'rooms.update_status',
            'housekeeping.view',
            'housekeeping.manage',
            'maintenance.view',
            'maintenance.manage',
        ]);

        $kitchenStaff = Role::firstOrCreate(
            ['name' => 'Kitchen Staff', 'guard_name' => 'web']
        );
        $kitchenStaff->syncPermissions([
            'kds.view',
            'kds.manage',
            'menu_items.view',
            'inventory.view',
            'groups.view',
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
            'folios.split',
            'folios.transfer',
            'payments.charge',
            'payments.refund',
            'crm.view',
            'crm.redeem',
            'cashier.open_shift',
            'cashier.close_shift',
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
            'audit.manage_anomaly_rules',
            'audit.confirm_anomaly',
            'audit.run_night_audit',
            'audit.retry_night_audit',
            'reports.view',
            'reports.export',
            'analytics.view',
            'analytics.manage',
            'analytics.export_warehouse',
            'api.view',
            'accounting_export.view',
            'accounting_export.manage',
            'accounting_export.retry',
            'settings.view',
            'settings.update',
            'settings.manage',
            'business_date.view',
            'idempotency.view',
            'journal.view',
            'journal.export',
            'system_health.view',
            'tax.view',
            'fiscal.view',
            'fiscal.retry',
            'accounting.view',
            'accounting.manage_chart',
            'accounting.close_period',
            'commissions.view',
            'inventory.view_costing',
            'privacy.view',
            'privacy.fulfill',
            'statutory.view',
            'statutory.generate',
            'cashier.approve_void',
        ]);
    }
}
