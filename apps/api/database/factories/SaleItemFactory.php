<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleItem>
 */
class SaleItemFactory extends Factory
{
    protected $model = SaleItem::class;

    public function definition(): array
    {
        return [
            'sale_id'             => Sale::factory(),
            'product_id'          => Product::factory(),
            'quantity_boxes'      => $this->faker->numberBetween(1, 10),
            'quantity_units'      => $this->faker->numberBetween(0, 4),
            'unit_price_amount'   => '750.0000',
            'unit_price_currency' => 'USD',
            'subtotal_amount'     => '750.0000',
            'exchange_rate_id'    => null,
        ];
    }
}
