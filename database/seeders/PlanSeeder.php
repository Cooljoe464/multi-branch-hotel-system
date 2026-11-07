<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Services\EntitlementService;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Launch catalog from spec section 4.2. Prices live with billing, not
     * here; this seeds what each tier may use.
     *
     * @var array<string, array{name: string, sort: int, features: list<string>, limits: array<string, int>}>
     */
    private const CATALOG = [
        'starter' => [
            'name' => 'Starter',
            'sort' => 1,
            'features' => ['pms.reservations', 'pms.front_desk', 'finance.folios'],
            'limits' => ['limits.rooms.max' => 15, 'limits.properties.max' => 1],
        ],
        'growth' => [
            'name' => 'Growth',
            'sort' => 2,
            'features' => [
                'pms.reservations', 'pms.front_desk', 'finance.folios',
                'pms.group_booking', 'wifi.vouchers',
            ],
            'limits' => ['limits.rooms.max' => 50, 'limits.properties.max' => 1],
        ],
        'professional' => [
            'name' => 'Professional',
            'sort' => 3,
            'features' => [
                'pms.reservations', 'pms.front_desk', 'finance.folios',
                'pms.group_booking', 'wifi.vouchers', 'wifi.tier_upsell',
                'locks.issue', 'pos.sales', 'channels.sync', 'events.bookings',
            ],
            'limits' => ['limits.rooms.max' => 100, 'limits.properties.max' => 1],
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'sort' => 4,
            'features' => [
                'pms.reservations', 'pms.front_desk', 'finance.folios',
                'pms.group_booking', 'wifi.vouchers', 'wifi.tier_upsell',
                'locks.issue', 'pos.sales', 'channels.sync', 'events.bookings',
            ],
            'limits' => ['limits.rooms.max' => 1000, 'limits.properties.max' => 50],
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::CATALOG as $key => $definition) {
            $plan = Plan::updateOrCreate(
                ['key' => $key],
                ['name' => $definition['name'], 'sort_order' => $definition['sort'], 'is_active' => true],
            );

            $kept = [];

            foreach ($definition['features'] as $feature) {
                $this->grant($plan, $feature, PlanEntitlement::TYPE_BOOLEAN, 'true');
                $kept[] = $feature;
            }

            foreach ($definition['limits'] as $limitKey => $limit) {
                $this->grant($plan, $limitKey, PlanEntitlement::TYPE_INTEGER_LIMIT, (string) $limit);
                $kept[] = $limitKey;
            }

            $plan->entitlements()->whereNotIn('entitlement_key', $kept)->delete();
        }

        app(EntitlementService::class)->flush();
    }

    private function grant(Plan $plan, string $key, string $type, string $value): void
    {
        PlanEntitlement::updateOrCreate(
            ['plan_id' => $plan->id, 'entitlement_key' => $key],
            ['value_type' => $type, 'value' => $value],
        );
    }
}
