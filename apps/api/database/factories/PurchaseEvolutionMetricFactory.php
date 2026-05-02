<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EvolutionState;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseEvolutionMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseEvolutionMetric>
 */
class PurchaseEvolutionMetricFactory extends Factory
{
    protected $model = PurchaseEvolutionMetric::class;

    public function definition(): array
    {
        return [
            'customer_id'        => Customer::factory(),
            'product_id'         => Product::factory(),
            'last_purchase_date' => now()->subDays(10)->toDateString(),
            'avg_interval_days'  => 30.0,
            'last_interval_days' => 30,
            'purchase_count'     => 3,
            'evolution_state'    => EvolutionState::Stable,
            'computed_at'        => now(),
        ];
    }

    public function inState(EvolutionState $state): static
    {
        return $this->state(fn () => ['evolution_state' => $state]);
    }

    public function firstPurchase(): static
    {
        return $this->state(fn () => [
            'evolution_state'    => EvolutionState::FirstPurchase,
            'purchase_count'     => 1,
            'avg_interval_days'  => null,
            'last_interval_days' => null,
        ]);
    }

    public function inactive(int $daysSinceLast = 65, int $avgInterval = 30): static
    {
        return $this->state(fn () => [
            'evolution_state'    => EvolutionState::Inactive,
            'last_purchase_date' => now()->subDays($daysSinceLast)->toDateString(),
            'avg_interval_days'  => (float) $avgInterval,
            'last_interval_days' => $daysSinceLast,
            'purchase_count'     => 3,
        ]);
    }
}
