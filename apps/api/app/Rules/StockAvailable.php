<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\CentralStock;
use App\Models\DistributorStock;
use App\Models\SellerStock;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a given source entity has at least `quantity` boxes available.
 *
 * Usage in Form Request:
 *
 *   new StockAvailable(
 *       sourceType: 'central',
 *       productId: $this->product_id,
 *       quantity: $this->quantity_boxes,
 *   )
 *
 *   new StockAvailable(
 *       sourceType: 'distributor',
 *       productId: $this->product_id,
 *       quantity: $this->quantity_boxes,
 *       sourceId: $this->distributor_id,
 *   )
 *
 * Supported sourceType values: 'central', 'distributor', 'seller'.
 */
final class StockAvailable implements ValidationRule
{
    public function __construct(
        private readonly string $sourceType,
        private readonly string $productId,
        private readonly int $quantity,
        private readonly ?string $sourceId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $available = $this->resolveAvailable();

        if ($available === null) {
            $fail('El stock de origen no existe para el producto seleccionado.');

            return;
        }

        if ($this->quantity > $available) {
            $fail(
                sprintf(
                    'Stock insuficiente. Disponible: %d cajas. Solicitado: %d cajas.',
                    $available,
                    $this->quantity,
                )
            );
        }
    }

    private function resolveAvailable(): ?int
    {
        return match ($this->sourceType) {
            'central'     => $this->centralAvailable(),
            'distributor' => $this->distributorAvailable(),
            'seller'      => $this->sellerAvailable(),
            default       => null,
        };
    }

    private function centralAvailable(): ?int
    {
        $row = CentralStock::where('product_id', $this->productId)->first();

        return $row?->available;
    }

    private function distributorAvailable(): ?int
    {
        if ($this->sourceId === null) {
            return null;
        }

        $row = DistributorStock::where('distributor_id', $this->sourceId)
            ->where('product_id', $this->productId)
            ->first();

        return $row?->available;
    }

    private function sellerAvailable(): ?int
    {
        if ($this->sourceId === null) {
            return null;
        }

        $row = SellerStock::where('seller_id', $this->sourceId)
            ->where('product_id', $this->productId)
            ->first();

        return $row !== null ? $row->availableBoxes() : null;
    }
}
