<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentMethod>
 */
class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    public function definition(): array
    {
        return [
            'code'                  => $this->faker->randomElement([
                'transfer_dermacells',
                'transfer_distributor',
                'cash',
                'credit_card',
                'check',
            ]),
            'name'                  => $this->faker->words(2, true),
            'requires_reference'    => false,
            'requires_installments' => false,
            'requires_check_fields' => false,
            'is_active'             => true,
        ];
    }

    public function cash(): static
    {
        return $this->state([
            'code'                  => 'cash',
            'name'                  => 'Efectivo',
            'requires_reference'    => false,
            'requires_installments' => false,
            'requires_check_fields' => false,
        ]);
    }

    public function transfer(): static
    {
        return $this->state([
            'code'               => 'transfer_dermacells',
            'name'               => 'Transferencia Dermacells',
            'requires_reference' => true,
        ]);
    }
}
