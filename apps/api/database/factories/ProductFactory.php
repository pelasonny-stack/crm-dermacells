<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for the Product model — used in Phase 4 stock tests.
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $names = ['Dermal', 'Pink', 'Capillary', 'Biomask'];

        return [
            'name'                => $this->faker->unique()->randomElement($names) . '-' . $this->faker->randomNumber(4),
            'units_per_box'       => 5,
            'base_price_amount'   => '750.0000',
            'base_price_currency' => 'USD',
            'is_active'           => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
