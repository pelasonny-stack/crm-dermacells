<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        $sale = Sale::factory()->create();

        return [
            'sale_id'           => $sale->id,
            'customer_id'       => $sale->customer_id,
            'payment_method_id' => PaymentMethod::factory(),
            'amount_amount'     => number_format($this->faker->randomFloat(2, 100, 5000), 4, '.', ''),
            'amount_currency'   => 'ARS',
            'exchange_rate_id'  => null,
            'is_advance'        => false,
            'reference'         => $this->faker->optional()->bothify('REF-#####'),
            'installments'      => null,
            'check_number'      => null,
            'check_bank'        => null,
            'check_due_date'    => null,
            'cash_destination'  => null,
            'cash_destination_dist_id' => null,
            'payment_date'      => $this->faker->date(),
            'reversed'          => false,
            'reversed_by'       => null,
            'reversed_at'       => null,
            'reversal_reason'   => null,
            'recorded_by'       => User::factory(),
        ];
    }

    public function reversed(): static
    {
        return $this->state([
            'reversed'       => true,
            'reversed_by'    => User::factory(),
            'reversed_at'    => now(),
            'reversal_reason' => 'Test reversal',
        ]);
    }

    public function advance(): static
    {
        return $this->state(['is_advance' => true]);
    }

    public function inUsd(): static
    {
        return $this->state([
            'amount_amount'   => $this->faker->randomFloat(4, 10, 500),
            'amount_currency' => 'USD',
        ]);
    }
}
