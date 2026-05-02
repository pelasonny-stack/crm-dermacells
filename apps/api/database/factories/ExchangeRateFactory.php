<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ExchangeRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExchangeRate>
 */
class ExchangeRateFactory extends Factory
{
    protected $model = ExchangeRate::class;

    public function definition(): array
    {
        return [
            'rate_date'        => $this->faker->unique()->date(),
            'rate_ars_per_usd' => $this->faker->randomFloat(6, 800, 1100),
            'source'           => 'api_bna',
            'recorded_by'      => null,
        ];
    }
}
