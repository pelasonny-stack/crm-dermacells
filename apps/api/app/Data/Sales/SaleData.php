<?php

declare(strict_types=1);

namespace App\Data\Sales;

use App\Enums\SaleStatus;
use Spatie\LaravelData\Data;

/**
 * API resource DTO for a Sale — used in GET /sales and GET /sales/{id} responses.
 *
 * This is a value object for outgoing data; not used for writes.
 * Constructed by SaleController from the Sale Eloquent model.
 */
class SaleData extends Data
{
    /**
     * @param list<SaleItemData> $items
     */
    public function __construct(
        public readonly string $id,
        public readonly string $customerId,
        public readonly string $sellerId,
        public readonly string $zoneId,
        public readonly SaleStatus $status,
        public readonly string $saleDate,
        public readonly string $paymentTermsId,
        public readonly ?string $dueDate,
        public readonly string $currency,
        public readonly ?string $exchangeRateId,
        public readonly string $totalAmount,
        public readonly string $totalCurrency,
        public readonly bool $delegatedDelivery,
        public readonly ?string $delegatedDistributorId,
        /** @var list<SaleItemData> */
        public readonly array $items,
        public readonly ?string $cancellationReason,
        public readonly ?string $cancelledAt,
        public readonly string $createdAt,
    ) {}

    /**
     * Build SaleData from an Eloquent Sale model (with items eager-loaded).
     *
     * @param \App\Models\Sale $sale
     */
    public static function fromModel(\App\Models\Sale $sale): self
    {
        return new self(
            id: $sale->id,
            customerId: $sale->customer_id,
            sellerId: $sale->seller_id,
            zoneId: $sale->zone_id,
            status: $sale->status,
            saleDate: $sale->sale_date->toDateString(),
            paymentTermsId: $sale->payment_terms_id,
            dueDate: $sale->due_date?->toDateString(),
            currency: $sale->currency,
            exchangeRateId: $sale->exchange_rate_id,
            totalAmount: (string) $sale->getRawOriginal('total_amount'),
            totalCurrency: $sale->total_currency,
            delegatedDelivery: $sale->delegated_delivery,
            delegatedDistributorId: $sale->delegated_distributor_id,
            items: $sale->items
                ->map(fn ($item) => new SaleItemData(
                    productId: $item->product_id,
                    quantityBoxes: $item->quantity_boxes,
                    quantityUnits: $item->quantity_units,
                    unitPriceAmount: (string) $item->getRawOriginal('unit_price_amount'),
                    unitPriceCurrency: $item->unit_price_currency,
                ))
                ->all(),
            cancellationReason: $sale->cancellation_reason,
            cancelledAt: $sale->cancelled_at?->toIso8601String(),
            createdAt: $sale->created_at?->toIso8601String() ?? '',
        );
    }
}
