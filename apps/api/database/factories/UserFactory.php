<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory for the CRM Dermacells User model.
 *
 * The User model uses UUID primary keys (Postgres gen_random_uuid() with
 * Eloquent HasUuids as fallback). There is no password — authentication is
 * exclusively via OAuth (Google / Microsoft) + Sanctum tokens.
 *
 * Default state creates a Seller with is_active=true. Use the provided state
 * methods to override role or status in specific test scenarios.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email'         => $this->faker->unique()->safeEmail(),
            'full_name'     => $this->faker->name(),
            'role'          => UserRole::Seller,
            'can_sell'      => false,
            'is_active'     => true,
            'ai_enabled'    => true,
            'oauth_provider' => 'google',
            'oauth_sub'     => Str::uuid()->toString(),
        ];
    }

    // -------------------------------------------------------------------------
    // Role state methods
    // -------------------------------------------------------------------------

    /**
     * A Director user (full access, no commissions per §2.4).
     */
    public function director(): static
    {
        return $this->state(fn (array $attributes) => [
            'role'     => UserRole::Director,
            'can_sell' => false,
        ]);
    }

    /**
     * A Director who also has the can_sell flag active (§2.3 — directors may
     * optionally make sales when the flag is enabled).
     */
    public function directorWithSell(): static
    {
        return $this->state(fn (array $attributes) => [
            'role'     => UserRole::Director,
            'can_sell' => true,
        ]);
    }

    /**
     * A Distributor user (zone owner, manages stock and settlements).
     */
    public function distributor(): static
    {
        return $this->state(fn (array $attributes) => [
            'role'     => UserRole::Distributor,
            'can_sell' => false,
        ]);
    }

    /**
     * A Seller user (field agent, default role).
     */
    public function seller(): static
    {
        return $this->state(fn (array $attributes) => [
            'role'     => UserRole::Seller,
            'can_sell' => false,
        ]);
    }

    // -------------------------------------------------------------------------
    // Status state methods
    // -------------------------------------------------------------------------

    /**
     * An inactive (deactivated) user — cannot log in or access the API.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active'      => false,
            'deactivated_at' => now(),
        ]);
    }
}
