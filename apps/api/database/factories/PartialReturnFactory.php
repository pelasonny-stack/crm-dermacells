<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PartialReturnStatus;
use App\Models\PartialReturn;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PartialReturn>
 */
class PartialReturnFactory extends Factory
{
    protected $model = PartialReturn::class;

    public function definition(): array
    {
        return [
            'sale_id'        => Sale::factory(),
            'sale_item_id'   => SaleItem::factory(),
            'initiated_by'   => User::factory()->seller(),
            'confirmed_by'   => null,
            'status'         => PartialReturnStatus::PendingDirectorConfirmation,
            'quantity_boxes' => 1,
            'quantity_units' => 0,
            'refund_amount'  => '750.0000',
            'refund_currency' => 'USD',
            'reason'         => $this->faker->sentence(),
            'initiated_at'   => now(),
            'confirmed_at'   => null,
        ];
    }
}
