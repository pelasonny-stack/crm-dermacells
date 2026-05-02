<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Alert;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Alert>
 */
class AlertFactory extends Factory
{
    protected $model = Alert::class;

    public function definition(): array
    {
        return [
            'alert_type'            => 'cycle_due_soon',
            'target_user_id'        => User::factory(),
            'reference_entity_type' => null,
            'reference_entity_id'   => null,
            'payload_json'          => ['product_id' => fake()->uuid()],
            'severity'              => 'info',
            'delivered'             => false,
            'delivered_at'          => null,
            'read_at'               => null,
            'created_at'            => now(),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => ['target_user_id' => $user->getKey()]);
    }

    public function delivered(): static
    {
        return $this->state(fn () => [
            'delivered'    => true,
            'delivered_at' => now(),
        ]);
    }

    public function read(): static
    {
        return $this->state(fn () => ['read_at' => now()]);
    }

    public function ofType(string $type): static
    {
        return $this->state(fn () => ['alert_type' => $type]);
    }

    public function withSeverity(string $severity): static
    {
        return $this->state(fn () => ['severity' => $severity]);
    }
}
