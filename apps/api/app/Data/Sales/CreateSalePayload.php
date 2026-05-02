<?php

declare(strict_types=1);

namespace App\Data\Sales;

use Spatie\LaravelData\Data;

/**
 * Top-level payload for creating a draft sale.
 *
 * Built from CreateDraftSaleRequest after all validation passes.
 * Passed to CreateDraftSaleAction::execute().
 */
class CreateSalePayload extends Data
{
    /**
     * @param list<SaleItemData> $items
     */
    public function __construct(
        public readonly string $customerId,
        public readonly string $sellerId,
        public readonly string $zoneId,
        public readonly string $saleDate,
        public readonly string $paymentTermsId,
        public readonly string $currency,
        public readonly ?string $exchangeRateId,
        public readonly bool $delegatedDelivery,
        public readonly ?string $delegatedDistributorId,
        /** @var list<SaleItemData> */
        public readonly array $items,
    ) {}
}
