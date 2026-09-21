<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\BusinessDateService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Deterministic demo tenant (no faker): 2 branches, 5 room types,
 * 60 rooms, 12 users across the 9 roles, BAR rate plans and open
 * business dates. Safe to re-run: every write is firstOrCreate.
 */
class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RoleSeeder::class);

        $lagos = $this->branch('DEMO-LOS', 'Demo Lagos Hotel', 'Lagos', true);
        $abuja = $this->branch('DEMO-ABV', 'Demo Abuja Suites', 'Abuja', false);

        foreach ([$lagos, $abuja] as $branch) {
            $this->inventory($branch);
            $this->ratePlans($branch);
            app(BusinessDateService::class)->current($branch);
        }

        $this->users($lagos, $abuja);
    }

    /**
     * @return array{branches: int, room_types: int, rooms: int, users: int}
     */
    public function counts(): array
    {
        return [
            'branches' => Branch::whereIn('code', ['DEMO-LOS', 'DEMO-ABV'])->count(),
            'room_types' => RoomType::whereHas('branch', fn ($q) => $q->whereIn('code', ['DEMO-LOS', 'DEMO-ABV']))->count(),
            'rooms' => Room::whereHas('branch', fn ($q) => $q->whereIn('code', ['DEMO-LOS', 'DEMO-ABV']))->count(),
            'users' => User::where('email', 'like', '%@demo.hotel')->count(),
        ];
    }

    private function branch(string $code, string $name, string $city, bool $primary): Branch
    {
        return Branch::firstOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'slug' => strtolower($code),
                'address' => '1 Demo Road',
                'city' => $city,
                'state' => $city,
                'country' => 'NG',
                'postal_code' => '100001',
                'phone' => '+234-1-000-0000',
                'email' => strtolower($code).'@demo.hotel',
                'timezone' => 'Africa/Lagos',
                'currency_code' => 'NGN',
                'currency_symbol' => '₦',
                'tax_rate' => 7.5,
                'tax_label' => 'VAT',
                'is_active' => true,
                'is_primary' => $primary,
            ]
        );
    }

    private function inventory(Branch $branch): void
    {
        $types = [
            ['Standard Room', 'DEMO-STD', 2500000, 20],
            ['Deluxe Room', 'DEMO-DLX', 3500000, 16],
            ['Junior Suite', 'DEMO-JRS', 5000000, 12],
            ['Executive Suite', 'DEMO-EXS', 7500000, 8],
            ['Presidential Suite', 'DEMO-PRE', 15000000, 4],
        ];

        $roomNumber = 100;

        foreach ($types as [$name, $code, $baseRate, $count]) {
            $type = RoomType::firstOrCreate(
                ['branch_id' => $branch->id, 'code' => $code],
                [
                    'name' => $name,
                    'description' => "Demo {$name}",
                    'base_rate' => $baseRate,
                    'max_occupancy' => 2,
                    'bed_count' => 1,
                    'bed_type' => 'queen',
                    'is_active' => true,
                    'amenities' => ['wifi', 'tv'],
                ]
            );

            for ($i = 0; $i < $count; $i++) {
                $roomNumber++;

                Room::firstOrCreate(
                    ['branch_id' => $branch->id, 'number' => (string) $roomNumber],
                    [
                        'room_type_id' => $type->id,
                        'floor' => (string) intdiv($roomNumber, 100),
                        'status' => 'available',
                        'is_accessible' => false,
                        'is_smoking' => false,
                        'is_active' => true,
                    ]
                );
            }
        }
    }

    private function ratePlans(Branch $branch): void
    {
        RatePlan::firstOrCreate(
            ['branch_id' => $branch->id, 'code' => 'DEMO-BAR'],
            [
                'room_type_id' => null,
                'name' => 'Demo Best Available Rate',
                'type' => 'bar',
                'rate_multiplier' => 1.0,
                'is_negotiable' => false,
                'valid_from' => now()->subYear()->toDateString(),
                'valid_to' => null,
                'is_active' => true,
            ]
        );
    }

    private function users(Branch $lagos, Branch $abuja): void
    {
        $accounts = [
            ['Demo Admin', 'admin@demo.hotel', 'Global Admin', $lagos, true],
            ['Demo Owner', 'owner@demo.hotel', 'Property Owner', $lagos, false],
            ['Demo GM Lagos', 'gm.lagos@demo.hotel', 'Branch GM', $lagos, false],
            ['Demo GM Abuja', 'gm.abuja@demo.hotel', 'Branch GM', $abuja, false],
            ['Demo Front Desk 1', 'frontdesk1@demo.hotel', 'Front Desk', $lagos, false],
            ['Demo Front Desk 2', 'frontdesk2@demo.hotel', 'Front Desk', $abuja, false],
            ['Demo Housekeeper', 'housekeeper@demo.hotel', 'Housekeeper', $lagos, false],
            ['Demo Kitchen', 'kitchen@demo.hotel', 'Kitchen Staff', $lagos, false],
            ['Demo Laundry', 'laundry@demo.hotel', 'Laundry Attendant', $lagos, false],
            ['Demo Cashier', 'cashier@demo.hotel', 'Cashier', $lagos, false],
            ['Demo Auditor', 'auditor@demo.hotel', 'Auditor', $lagos, false],
            ['Demo Night Auditor', 'night@demo.hotel', 'Auditor', $abuja, false],
        ];

        foreach ($accounts as [$name, $email, $role, $branch, $globalAdmin]) {
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make('password'),
                    'branch_id' => $branch->id,
                    'is_global_admin' => $globalAdmin,
                    'gdpr_consent_at' => now(),
                    'gdpr_consent_version' => '1.0',
                ]
            );

            $user->syncRoles([Role::findByName($role)]);
            $user->branches()->syncWithoutDetaching([$lagos->id, $abuja->id]);
        }
    }
}
