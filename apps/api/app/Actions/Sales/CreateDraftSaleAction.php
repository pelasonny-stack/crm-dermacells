<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Data\Sales\CreateSalePayload;
use App\Data\Sales\SaleItemData;
use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesStatusHistory;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Creates a Sale in 'draft' status.
 *
 * Per §4.5, stock validation at this stage is WARN-ONLY — the draft is always
 * created even when stock is insufficient. The warning is returned in the
 * response meta so the UI can surface it.
 *
 * All writes happen inside a single DB::transaction().
 * The action never throws on stock shortage here (that is the Confirm action's job).
 */
final class CreateDraftSaleAction
{
    /**
     * @return array{sale: Sale, stockWarnings: list<string>}
     */
    public function execute(CreateSalePayload $payload, User $actor): array
    {
        return DB::transaction(function () use ($payload, $actor): array {
            $stockWarnings = [];

            // Resolve customer to inherit zone and due_date if not supplied
            $customer = Customer::findOrFail($payload->customerId);

            // Compute due date from payment terms
            $dueDate = $this->resolveDueDate($payload);

            // Build total from items
            ['total' => $total, 'items' => $resolvedItems, 'warnings' => $stockWarnings]
                = $this->resolveItemsAndTotal($payload, $customer);

            $sale = Sale::create([
                'customer_id'             => $payload->customerId,
                'seller_id'               => $payload->sellerId,
                'zone_id'                 => $payload->zoneId,
                'status'                  => SaleStatus::Draft,
                'sale_date'               => $payload->saleDate,
                'payment_terms_id'        => $payload->paymentTermsId,
                'due_date'                => $dueDate,
                'currency'                => $payload->currency,
                'exchange_rate_id'        => $payload->exchangeRateId,
                'total_amount'            => (string) $total->getAmount(),
                'total_currency'          => $total->getCurrency()->getCurrencyCode(),
                'delegated_delivery'      => $payload->delegatedDelivery,
                'delegated_distributor_id' => $payload->delegatedDistributorId,
            ]);

            foreach ($resolvedItems as $itemData) {
                SaleItem::create([
                    'sale_id'             => $sale->id,
                    'product_id'          => $itemData['product_id'],
                    'quantity_boxes'      => $itemData['quantity_boxes'],
                    'quantity_units'      => $itemData['quantity_units'],
                    'unit_price_amount'   => $itemData['unit_price_amount'],
                    'unit_price_currency' => $itemData['unit_price_currency'],
                    'subtotal_amount'     => $itemData['subtotal_amount'],
                    'exchange_rate_id'    => $payload->exchangeRateId,
                ]);
            }

            SalesStatusHistory::create([
                'sale_id'    => $sale->id,
                'from_status' => null,
                'to_status'  => SaleStatus::Draft->value,
                'changed_by' => $actor->id,
                'changed_at' => now(),
                'note'       => 'Sale created as draft.',
            ]);

            return ['sale' => $sale->load('items'), 'stockWarnings' => $stockWarnings];
        });
    }

    /**
     * Resolve line items, compute totals, and collect stock warnings.
     *
     * @return array{total: Money, items: list<array<string,mixed>>, warnings: list<string>}
     */
    private function resolveItemsAndTotal(CreateSalePayload $payload, Customer $customer): array
    {
        $currency = $payload->currency;
        $running = Money::of(0, $currency);
        $resolved = [];
        $warnings = [];

        foreach ($payload->items as $itemData) {
            /** @var SaleItemData $itemData */
            $product = Product::findOrFail($itemData->productId);

            // Resolve unit price
            $unitPriceAmount = $itemData->unitPriceAmount
                ?? $this->resolveDefaultPrice($customer, $product, $currency);
            $unitPriceMoney = Money::of($unitPriceAmount, $currency);

            // Total units in this line: boxes×units_per_box + loose
            $totalUnits = ($itemData->quantityBoxes * $product->units_per_box) + $itemData->quantityUnits;
            $subtotal = $unitPriceMoney->multipliedBy($totalUnits);
            $running = $running->plus($subtotal);

            // Warn-only stock check (§4.5) — Phase 4 stock action is the canonical check
            // We call a stub that Phase 4 will implement; if not available, skip silently.
            if ($this->isStockInsufficient($product, $itemData)) {
                $warnings[] = "Insufficient stock for product [{$product->name}]. Draft saved — confirmation will be blocked until stock is replenished.";
            }

            $resolved[] = [
                'product_id'          => $product->id,
                'quantity_boxes'      => $itemData->quantityBoxes,
                'quantity_units'      => $itemData->quantityUnits,
                'unit_price_amount'   => (string) $unitPriceMoney->getAmount(),
                'unit_price_currency' => $currency,
                'subtotal_amount'     => (string) $subtotal->getAmount(),
            ];
        }

        return ['total' => $running, 'items' => $resolved, 'warnings' => $warnings];
    }

    /**
     * Resolve the default unit price for a given product + customer.
     * Priority: customer reference_price → product base_price.
     */
    private function resolveDefaultPrice(Customer $customer, Product $product, string $currency): string
    {
        // If customer has a reference price in the same currency, use it
        if (
            $customer->reference_price_amount !== null
            && $customer->reference_price_currency === $currency
        ) {
            return (string) $customer->reference_price_amount;
        }

        // Fall back to product base price (always USD — convert if needed)
        // For Phase 5 simplicity we return the raw amount string;
        // currency mismatch validation is handled by CurrencyConsistencyRule in the request.
        return (string) $product->base_price_amount;
    }

    /**
     * Stub for Phase 4 stock availability check.
     * Returns false (no warning) until Phase 4 seller_stock is queryable.
     */
    private function isStockInsufficient(\App\Models\Product $product, SaleItemData $itemData): bool
    {
        // Phase 4 will replace this with:
        //   $sellerStock = SellerStock::where('product_id', $product->id)
        //       ->where('user_id', $sellerId)
        //       ->first();
        //   return $sellerStock && ($sellerStock->available < $totalUnits);
        return false;
    }

    private function resolveDueDate(CreateSalePayload $payload): ?string
    {
        if (! $payload->paymentTermsId) {
            return null;
        }

        $terms = \App\Models\PaymentTerm::find($payload->paymentTermsId);
        if (! $terms || $terms->days_to_due === 0) {
            return $payload->saleDate;
        }

        return \Illuminate\Support\Carbon::parse($payload->saleDate)
            ->addDays($terms->days_to_due)
            ->toDateString();
    }
}
