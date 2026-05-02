<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for the zones table.
 *
 * Default state creates a zone with a distributor. Use ->directZone() to
 * create a "zona directa" (distributor_id = NULL), meaning stock is dispatched
 * by a Director directly to Sellers in that zone (§2.3).
 *
 * @extends Factory<Zone>
 */
class ZoneFactory extends Factory
{
    protected $model = Zone::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name'           => $this->faker->unique()->city() . ' Zone',
            'distributor_id' => User::factory()->distributor(),
            'is_active'      => true,
        ];
    }

    /**
     * A "zona directa" — no distributor, Director manages stock directly.
     */
    public function directZone(): static
    {
        return $this->state(fn (array $attributes) => [
            'distributor_id' => null,
        ]);
    }
}
