<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PaymentTerm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for PaymentTerm.
 *
 * @extends Factory<PaymentTerm>
 */
class PaymentTermFactory extends Factory
{
    protected $model = PaymentTerm::class;

    public function definition(): array
    {
        return [
            'name'        => 'Contado',
            'days_to_due' => 0,
            'is_active'   => true,
        ];
    }
}
