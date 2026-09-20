<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuItem>
 */
class MenuItemFactory extends Factory
{
    protected $model = MenuItem::class;

    public function definition(): array
    {
        $categoryOptions = ['food', 'drink', 'laundry', 'service'];
        $category = $categoryOptions[array_rand($categoryOptions)];

        $names = [
            'food' => ['Grilled Chicken', 'Caesar Salad', 'Pasta Carbonara', 'Beef Steak', 'Fish & Chips'],
            'drink' => ['Fresh Orange Juice', 'Espresso', 'Sparkling Water', 'House Wine', 'Cocktail'],
            'laundry' => ['Shirt Wash', 'Suit Dry Clean', 'Towel Exchange', 'Sheet Change'],
            'service' => ['Spa Treatment', 'Airport Transfer', 'Mini Bar Restock', 'Extra Pillow'],
        ];

        $nameOptions = $names[$category];
        $name = $nameOptions[array_rand($nameOptions)];

        return [
            'branch_id' => Branch::factory(),
            'category' => $category,
            'name' => $name,
            'description' => fake()->sentence(),
            'price' => fake()->numberBetween(500, 15000),
            'image_url' => null,
            'is_available' => true,
            'is_active' => true,
            'dietary_flags' => null,
            'sort_order' => fake()->numberBetween(0, 100),
            'metadata' => null,
        ];
    }

    public function food(): static
    {
        return $this->state(fn () => ['category' => 'food']);
    }

    public function drink(): static
    {
        return $this->state(fn () => ['category' => 'drink']);
    }

    public function laundry(): static
    {
        return $this->state(fn () => ['category' => 'laundry']);
    }

    public function service(): static
    {
        return $this->state(fn () => ['category' => 'service']);
    }

    public function available(): static
    {
        return $this->state(fn () => ['is_available' => true]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn () => ['is_available' => false]);
    }

    public function forBranch(int $branchId): static
    {
        return $this->state(fn () => ['branch_id' => $branchId]);
    }
}
