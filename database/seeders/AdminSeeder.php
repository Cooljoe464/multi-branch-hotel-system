<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $branches = $this->createBranches();
        $roles = $this->getRoles();

        $this->createUsers($branches, $roles);
    }

    private function createBranches(): array
    {
        $downtown = Branch::firstOrCreate(
            ['code' => 'GHD-001'],
            [
                'name' => 'Grand Hotel Downtown',
                'slug' => 'grand-hotel-downtown',
                'address' => '123 Main Street',
                'city' => 'New York',
                'state' => 'NY',
                'country' => 'US',
                'postal_code' => '10001',
                'phone' => '+1-212-555-0100',
                'email' => 'downtown@grandhotel.com',
                'timezone' => 'America/New_York',
                'currency_code' => 'USD',
                'currency_symbol' => '$',
                'tax_rate' => 8.875,
                'tax_label' => 'NYC Tax',
                'is_active' => true,
                'is_primary' => true,
            ]
        );

        $seaside = Branch::firstOrCreate(
            ['code' => 'SRR-002'],
            [
                'name' => 'Seaside Resort & Retreat',
                'slug' => 'seaside-resort-retreat',
                'address' => '456 Ocean Drive',
                'city' => 'Miami',
                'state' => 'FL',
                'country' => 'US',
                'postal_code' => '33139',
                'phone' => '+1-305-555-0200',
                'email' => 'reservations@seasideresort.com',
                'timezone' => 'America/New_York',
                'currency_code' => 'USD',
                'currency_symbol' => '$',
                'tax_rate' => 7.0,
                'tax_label' => 'Tourist Tax',
                'is_active' => true,
                'is_primary' => false,
            ]
        );

        return compact('downtown', 'seaside');
    }

    private function getRoles(): array
    {
        return [
            'global_admin' => Role::findByName('Global Admin'),
            'property_owner' => Role::findByName('Property Owner'),
            'branch_gm' => Role::findByName('Branch GM'),
            'front_desk' => Role::findByName('Front Desk'),
            'housekeeper' => Role::findByName('Housekeeper'),
        ];
    }

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
                'password' => 'password',
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
                'password' => 'password',
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
                'password' => 'password',
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
                'password' => 'password',
                'is_global_admin' => false,
                'gdpr_consent_at' => now(),
                'gdpr_consent_version' => '1.0',
            ]
        );
        $housekeeper->syncRoles([$roles['housekeeper']]);
        $housekeeper->branches()->sync([$branches['downtown']->id]);
        $housekeeper->update(['branch_id' => $branches['downtown']->id]);
    }
}
