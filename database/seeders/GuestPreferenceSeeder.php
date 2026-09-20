<?php

namespace Database\Seeders;

use App\Models\Guest;
use App\Models\GuestPreference;
use Illuminate\Database\Seeder;

class GuestPreferenceSeeder extends Seeder
{
    public function run(): void
    {
        $guests = Guest::all();

        foreach ($guests as $guest) {
            $preferences = $this->getPreferencesForGuest($guest);

            foreach ($preferences as $pref) {
                GuestPreference::firstOrCreate(
                    [
                        'guest_id' => $guest->id,
                        'category' => $pref['category'],
                        'key' => $pref['key'],
                    ],
                    [
                        'value' => $pref['value'],
                        'notes' => $pref['notes'] ?? null,
                    ]
                );
            }
        }
    }

    /**
     * @return list<array{category: string, key: string, value: string, notes?: string}>
     */
    private function getPreferencesForGuest(Guest $guest): array
    {
        $preferences = [];

        $preferences[] = [
            'category' => 'room',
            'key' => 'floor',
            'value' => in_array($guest->vip_status, ['diamond', 'platinum']) ? 'high' : 'mid',
            'notes' => 'VIP guests prefer high floors',
        ];

        $preferences[] = [
            'category' => 'room',
            'key' => 'view',
            'value' => $guest->vip_status === 'diamond' ? 'city' : 'pool',
        ];

        $preferences[] = [
            'category' => 'pillow',
            'key' => 'type',
            'value' => 'down',
        ];

        $dietaryRestrictions = is_array(json_decode($guest->dietary_restrictions ?? '', true)) ? json_decode($guest->dietary_restrictions ?? '', true) : [];

        if (count($dietaryRestrictions) > 0) {
            $preferences[] = [
                'category' => 'minibar',
                'key' => 'stock',
                'value' => 'no_alcohol',
                'notes' => 'Dietary restrictions noted',
            ];
        } else {
            $preferences[] = [
                'category' => 'minibar',
                'key' => 'stock',
                'value' => 'full',
            ];
        }

        $preferences[] = [
            'category' => 'temperature',
            'key' => 'setting',
            'value' => 'moderate',
        ];

        if ($guest->vip_status === 'diamond') {
            $preferences[] = [
                'category' => 'newspaper',
                'key' => 'daily',
                'value' => 'international',
            ];

            $preferences[] = [
                'category' => 'wake_up_call',
                'key' => 'time',
                'value' => '07:00',
                'notes' => 'Diamond VIP - ensure prompt service',
            ];
        } elseif ($guest->vip_status === 'platinum') {
            $preferences[] = [
                'category' => 'newspaper',
                'key' => 'daily',
                'value' => 'business',
            ];
        }

        return $preferences;
    }
}
