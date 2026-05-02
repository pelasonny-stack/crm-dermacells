<?php

declare(strict_types=1);

namespace App\Data\Sales;

use Spatie\LaravelData\Data;

/**
 * DTO for a single line item within a sale creation payload.
 *
 * Validated by CreateDraftSaleRequest before being hydrated here.
 * unit_price is optional on creation: if absent, the Action resolves it from
 * the customer's reference_price or the product's base_price.
 */
class SaleItemData extends Data
{
    public function __construct(
        public readonly string $productId,
        public readonly int $quantityBoxes,
        public readonly int $quantityUnits,
        /** Unit price amount. NULL = use customer reference price or product base price. */
        public readonly ?string $unitPriceAmount,
        public readonly string $unitPriceCurrency,
    ) {}
}
