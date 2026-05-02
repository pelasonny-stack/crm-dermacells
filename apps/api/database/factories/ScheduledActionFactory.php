<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\ScheduledAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for ScheduledAction.
 *
 * @extends Factory<ScheduledAction>
 */
class ScheduledActionFactory extends Factory
{
    protected $model = ScheduledAction::class;

    public function definition(): array
    {
        return [
            'customer_id'    => Customer::factory(),
            'created_by'     => User::factory()->seller(),
            'scheduled_date' => now()->addDays($this->faker->numberBetween(1, 30))->toDateString(),
            'note'           => $this->faker->sentence(),
            'is_resolved'    => false,
            'resolved_at'    => null,
        ];
    }

    /** Action due today (for dispatch tests). */
    public function dueToday(): static
    {
        return $this->state(fn () => [
            'scheduled_date' => now()->toDateString(),
        ]);
    }

    /** Already resolved action. */
    public function resolved(): static
    {
        return $this->state(fn () => [
            'is_resolved' => true,
            'resolved_at' => now()->subDay(),
        ]);
    }
}
