<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IvaCondition;
use App\Models\Customer;
use App\Models\CustomerBillingEntity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerBillingEntity>
 */
class CustomerBillingEntityFactory extends Factory
{
    protected $model = CustomerBillingEntity::class;

    public function definition(): array
    {
        return [
            'customer_id'      => Customer::factory(),
            'name'             => $this->faker->company(),
            'cuit'             => $this->generateCuit(),
            'iva_condition'    => IvaCondition::ConsumidorFinal,
            'is_primary'       => true,
            'xubio_cliente_id' => null,
        ];
    }

    public function withXubioClienteId(string $id): static
    {
        return $this->state(['xubio_cliente_id' => $id]);
    }

    private function generateCuit(): string
    {
        // 11-digit CUIT placeholder; real validation lives in CuitFormatRule.
        return (string) $this->faker->numberBetween(20000000000, 27999999999);
    }
}
