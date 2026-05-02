<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\PaymentTerm;
use App\Models\Sale;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    protected $model = Sale::class;

    public function definition(): array
    {
        return [
            'customer_id'             => Customer::factory(),
            'seller_id'               => User::factory()->seller(),
            'zone_id'                 => Zone::factory(),
            'status'                  => SaleStatus::Draft,
            'sale_date'               => $this->faker->date(),
            'payment_terms_id'        => PaymentTerm::factory(),
            'due_date'                => null,
            'currency'                => 'USD',
            'exchange_rate_id'        => null,
            'total_amount'            => '750.0000',
            'total_currency'          => 'USD',
            'delegated_delivery'      => false,
            'delegated_distributor_id' => null,
            'cancellation_reason'     => null,
            'cancelled_by'            => null,
            'cancelled_at'            => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Sale $sale): void {
            // ARS sales require an exchange_rate_id (DB constraint).
            // Auto-create a rate record when the factory produces an ARS sale
            // without one being explicitly provided.
            if ($sale->currency === 'ARS' && $sale->exchange_rate_id === null) {
                $rate = ExchangeRate::factory()->create([
                    'rate_ars_per_usd' => '1000.000000',
                ]);
                $sale->exchange_rate_id = $rate->id;
            }
        });
    }

    public function draft(): static
    {
        return $this->state(['status' => SaleStatus::Draft]);
    }

    public function confirmed(): static
    {
        return $this->state(['status' => SaleStatus::Confirmed]);
    }

    public function delivered(): static
    {
        return $this->state(['status' => SaleStatus::Delivered]);
    }

    public function cancelled(): static
    {
        return $this->state([
            'status'              => SaleStatus::Cancelled,
            'cancellation_reason' => $this->faker->sentence(),
            'cancelled_at'        => now(),
        ]);
    }

    public function inArs(): static
    {
        return $this->state(function () {
            return [
                'currency'         => 'ARS',
                'total_currency'   => 'ARS',
                'total_amount'     => '675000.0000',
                'exchange_rate_id' => ExchangeRate::factory()->create([
                    'rate_ars_per_usd' => '1000.000000',
                ])->id,
            ];
        });
    }

    public function delegated(string $distributorId): static
    {
        return $this->state([
            'delegated_delivery'      => true,
            'delegated_distributor_id' => $distributorId,
        ]);
    }
}
