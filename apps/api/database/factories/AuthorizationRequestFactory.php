<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AuthorizationType;
use App\Models\AuthorizationRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuthorizationRequest>
 */
class AuthorizationRequestFactory extends Factory
{
    protected $model = AuthorizationRequest::class;

    public function definition(): array
    {
        return [
            'type'           => $this->faker->randomElement(AuthorizationType::cases()),
            'sale_id'        => null,
            'requested_by'   => User::factory()->seller(),
            'current_value'  => '750.0000',
            'proposed_value' => (string) number_format($this->faker->randomFloat(4, 500, 749), 4, '.', ''),
            'value_currency' => 'USD',
            'reason'         => $this->faker->sentence(10),
            'status'         => 'pending',
            'resolved_by'    => null,
            'resolved_at'    => null,
            'rejection_reason' => null,
            'expires_at'     => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => 'pending']);
    }

    public function approved(User $director): static
    {
        return $this->state([
            'status'      => 'approved',
            'resolved_by' => $director->id,
            'resolved_at' => now(),
        ]);
    }

    public function rejected(User $director, string $reason = 'No aplica.'): static
    {
        return $this->state([
            'status'           => 'rejected',
            'resolved_by'      => $director->id,
            'resolved_at'      => now(),
            'rejection_reason' => $reason,
        ]);
    }

    public function forSale(string $saleId): static
    {
        return $this->state(['sale_id' => $saleId]);
    }

    public function priceChange(): static
    {
        return $this->state([
            'type'           => AuthorizationType::PriceChange,
            'value_currency' => 'USD',
        ]);
    }

    public function exchangeRateChange(): static
    {
        return $this->state([
            'type'           => AuthorizationType::ExchangeRateChange,
            'value_currency' => null,
        ]);
    }
}
