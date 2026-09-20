<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $branches = $this->createBranches();
        $roles = $this->getRoles();

        $this->createUsers($branches, $roles);
    }

    /**
     * @return array{downtown: Branch, seaside: Branch}
     */
    private function createBranches(): array
    {
        $downtown = Branch::firstOrCreate(
            ['code' => 'EGH-001'],
            [
                'name' => 'Eko Grand Hotel',
                'slug' => 'eko-grand-hotel',
                'address' => '42 Marina Road, Lagos Island',
                'city' => 'Lagos',
                'state' => 'Lagos',
                'country' => 'NG',
                'postal_code' => '102273',
                'phone' => '+234-1-271-0200',
                'email' => 'info@ekograndhotel.com',
                'timezone' => 'Africa/Lagos',
                'currency_code' => 'NGN',
                'currency_symbol' => '₦',
                'tax_rate' => 7.5,
                'tax_label' => 'VAT',
                'is_active' => true,
                'is_primary' => true,
            ]
        );

        $seaside = Branch::firstOrCreate(
            ['code' => 'CBR-002'],
            [
                'name' => 'Calabar Beach Resort & Spa',
                'slug' => 'calabar-beach-resort-spa',
                'address' => '15 Beach Road, Calabar',
                'city' => 'Calabar',
                'state' => 'Cross River',
                'country' => 'NG',
                'postal_code' => '540242',
                'phone' => '+234-87-234-5678',
                'email' => 'reservations@calabarbeachresort.com',
                'timezone' => 'Africa/Lagos',
                'currency_code' => 'NGN',
                'currency_symbol' => '₦',
                'tax_rate' => 7.5,
                'tax_label' => 'VAT',
                'is_active' => true,
                'is_primary' => false,
            ]
        );

        return compact('downtown', 'seaside');
    }

    /**
     * @return array{global_admin: \Spatie\Permission\Contracts\Role, property_owner: \Spatie\Permission\Contracts\Role, branch_gm: \Spatie\Permission\Contracts\Role, front_desk: \Spatie\Permission\Contracts\Role, housekeeper: \Spatie\Permission\Contracts\Role, kitchen_staff: \Spatie\Permission\Contracts\Role, laundry_attendant: \Spatie\Permission\Contracts\Role, cashier: \Spatie\Permission\Contracts\Role, auditor: \Spatie\Permission\Contracts\Role}
     */
    private function getRoles(): array
    {
        return [
            'global_admin' => Role::findByName('Global Admin'),
            'property_owner' => Role::findByName('Property Owner'),
            'branch_gm' => Role::findByName('Branch GM'),
            'front_desk' => Role::findByName('Front Desk'),
            'housekeeper' => Role::findByName('Housekeeper'),
            'kitchen_staff' => Role::findByName('Kitchen Staff'),
            'laundry_attendant' => Role::findByName('Laundry Attendant'),
            'cashier' => Role::findByName('Cashier'),
            'auditor' => Role::findByName('Auditor'),
        ];
    }

    /**
     * @param  array{downtown: Branch, seaside: Branch}  $branches
     * @param  array{global_admin: \Spatie\Permission\Contracts\Role, property_owner: \Spatie\Permission\Contracts\Role, branch_gm: \Spatie\Permission\Contracts\Role, front_desk: \Spatie\Permission\Contracts\Role, housekeeper: \Spatie\Permission\Contracts\Role, kitchen_staff: \Spatie\Permission\Contracts\Role, laundry_attendant: \Spatie\Permission\Contracts\Role, cashier: \Spatie\Permission\Contracts\Role, auditor: \Spatie\Permission\Contracts\Role}  $roles
     */
    private function createUsers(array $branches, array $roles): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@hotel.com'],
            [
                'name' => 'System Admin',
                'password' => 'password',
                'is_global_admin' => true,
                'gdpr_consent_at' => now(),
                'gdpr_consent_version' => '1.0',
            ]
        );
        $admin->syncRoles([$roles['global_admin']]);
        $admin->branches()->sync([$branches['downtown']->id, $branches['seaside']->id]);
        $admin->update(['branch_id' => $branches['downtown']->id]);

        $owner = User::firstOrCreate(
            ['email' => 'owner@hotel.com'],
            [
                'name' => 'Property Owner',
                'password' => Hash::make('password'),
                'is_global_admin' => false,
                'gdpr_consent_at' => now(),
                'gdpr_consent_version' => '1.0',
            ]
        );
        $owner->syncRoles([$roles['property_owner']]);
        $owner->branches()->sync([$branches['downtown']->id, $branches['seaside']->id]);
        $owner->update(['branch_id' => $branches['downtown']->id]);

        $gm = User::firstOrCreate(
            ['email' => 'gm@hotel.com'],
            [
                'name' => 'Branch General Manager',
                'password' => Hash::make('password'),
                'is_global_admin' => false,
                'gdpr_consent_at' => now(),
                'gdpr_consent_version' => '1.0',
            ]
        );
        $gm->syncRoles([$roles['branch_gm']]);
        $gm->branches()->sync([$branches['downtown']->id]);
        $gm->update(['branch_id' => $branches['downtown']->id]);

        $frontDesk = User::firstOrCreate(
            ['email' => 'frontdesk@hotel.com'],
            [
                'name' => 'Front Desk Staff',
                'password' => Hash::make('password'),
                'is_global_admin' => false,
                'gdpr_consent_at' => now(),
                'gdpr_consent_version' => '1.0',
            ]
        );
        $frontDesk->syncRoles([$roles['front_desk']]);
        $frontDesk->branches()->sync([$branches['downtown']->id]);
        $frontDesk->update(['branch_id' => $branches['downtown']->id]);

        $housekeeper = User::firstOrCreate(
            ['email' => 'housekeeper@hotel.com'],
            [
                'name' => 'Housekeeping Staff',
                'password' => Hash::make('password'),
                'is_global_admin' => false,
                'gdpr_consent_at' => now(),
                'gdpr_consent_version' => '1.0',
            ]
        );
        $housekeeper->syncRoles([$roles['housekeeper']]);
        $housekeeper->branches()->sync([$branches['downtown']->id]);
        $housekeeper->update(['branch_id' => $branches['downtown']->id]);

        $kitchenStaff = User::firstOrCreate(
            ['email' => 'kitchenstaff@hotel.com'],
            [
                'name' => 'Kitchen Staff',
                'password' => Hash::make('password'),
                'is_global_admin' => false,
                'gdpr_consent_at' => now(),
                'gdpr_consent_version' => '1.0',
            ]
        );
        $kitchenStaff->syncRoles([$roles['kitchen_staff']]);
        $kitchenStaff->branches()->sync([$branches['downtown']->id]);
        $kitchenStaff->update(['branch_id' => $branches['downtown']->id]);

        $laundryAttendant = User::firstOrCreate(
            ['email' => 'laundry@hotel.com'],
            [
                'name' => 'Laundry Attendant',
                'password' => Hash::make('password'),
                'is_global_admin' => false,
                'gdpr_consent_at' => now(),
                'gdpr_consent_version' => '1.0',
            ]
        );
        $laundryAttendant->syncRoles([$roles['laundry_attendant']]);
        $laundryAttendant->branches()->sync([$branches['downtown']->id]);
        $laundryAttendant->update(['branch_id' => $branches['downtown']->id]);

        $cashier = User::firstOrCreate(
            ['email' => 'cashier@hotel.com'],
            [
                'name' => 'Cashier',
                'password' => Hash::make('password'),
                'is_global_admin' => false,
                'gdpr_consent_at' => now(),
                'gdpr_consent_version' => '1.0',
            ]
        );
        $cashier->syncRoles([$roles['cashier']]);
        $cashier->branches()->sync([$branches['downtown']->id]);
        $cashier->update(['branch_id' => $branches['downtown']->id]);

        $auditor = User::firstOrCreate(
            ['email' => 'auditor@hotel.com'],
            [
                'name' => 'Auditor',
                'password' => Hash::make('password'),
                'is_global_admin' => false,
                'gdpr_consent_at' => now(),
                'gdpr_consent_version' => '1.0',
            ]
        );
        $auditor->syncRoles([$roles['auditor']]);
        $auditor->branches()->sync([$branches['downtown']->id]);
        $auditor->update(['branch_id' => $branches['downtown']->id]);
    }
}
