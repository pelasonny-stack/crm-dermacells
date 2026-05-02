<?php

declare(strict_types=1);

namespace App\Domain\DistributorFinance\Services;

use App\Enums\PreferredCostModality;
use App\Enums\SaleStatus;
use App\Models\DistributorPreferredCost;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Context\CustomContext;
use Brick\Money\Money;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * DistributorMarginService — §9.1, §9.3
 *
 * Responsible for:
 *   1. Resolving the preferred cost per box for a (Distributor, Product) pair.
 *   2. Computing the gross margin for a single delivered Sale.
 *   3. Aggregating the gross margin for a Distributor over a CarbonPeriod.
 *
 * DESIGN NOTES
 * ============
 * - All Money arithmetic uses brick/money — immutable, currency-safe.
 * - discount_pct is applied against the product's base_price (base_price_amount,
 *   base_price_currency). The result is in the same currency as the base price.
 * - When no preferred cost is configured for a (distributor, product) pair,
 *   we fall back to the product's base_price (zero margin — conservative).
 * - marginForSale computes item-by-item and sums. All items in a single sale
 *   share the same currency (enforced by the sales table constraint), so
 *   brick/money's same-currency addition is safe here.
 * - marginInPeriod aggregates over all delivered sales in zones where the
 *   distributor is assigned, for the given period and currency.
 */
final class DistributorMarginService
{
    /**
     * Resolve the preferred cost per box for a given (Distributor, Product) pair.
     *
     * For fixed_price modality: returns the configured fixed price directly.
     * For discount_pct modality: applies the discount to the product's base_price.
     *
     * Falls back to the product's base_price when no preferred cost is configured
     * (zero margin for that product — alerts the caller implicitly via the
     * resulting margin being zero).
     *
     * @param User    $distributor The Distributor user.
     * @param Product $product     The product being valued.
     * @return Money                Cost per box in the configured / base currency.
     */
    public function resolvePreferredCostPerBox(User $distributor, Product $product): Money
    {
        /** @var DistributorPreferredCost|null $config */
        $config = DistributorPreferredCost::query()
            ->where('distributor_id', $distributor->id)
            ->where('product_id', $product->id)
            ->first();

        // No configuration → fall back to base price (0 margin)
        if ($config === null) {
            return $this->productBasePrice($product);
        }

        return match ($config->modality) {
            PreferredCostModality::FixedPrice => Money::of(
                BigDecimal::of((string) $config->value),
                $config->currency,
                null,
                RoundingMode::HALF_UP,
            ),

            PreferredCostModality::DiscountPct => $this->applyDiscount(
                basePrice: $this->productBasePrice($product),
                discountFraction: BigDecimal::of((string) $config->value),
            ),
        };
    }

    /**
     * Compute the gross margin for a single Sale.
     *
     * Margin = sum(item.subtotal) − sum(resolvedCostPerBox × item.quantity_boxes)
     *
     * IMPORTANT: the sale must have its items eagerly loaded (or will be
     * lazy-loaded). All items must share the same currency (guaranteed by DB
     * constraint). The returned Money is in that same currency.
     *
     * Returns Money::zero('USD') when the sale has no items.
     *
     * @param Sale $sale A delivered sale with items loaded (or loadable).
     * @return Money     Gross margin in the sale's currency.
     */
    public function marginForSale(Sale $sale): Money
    {
        $items = $sale->items()->with('product')->get();

        if ($items->isEmpty()) {
            return Money::zero($sale->currency);
        }

        // Initialise accumulators using CustomContext(4) to match the precision
        // used by MoneyCast when hydrating SaleItem subtotal and unit_price values
        // from the DB (NUMERIC 18,4 columns). brick/money's plus() / minus() require
        // both operands to share the same context — mixing ISO context (2 decimals)
        // with CustomContext(4) throws MoneyMismatchException.
        $ctx4         = new CustomContext(4);
        $totalRevenue = Money::of(0, $sale->currency, $ctx4);
        $totalCost    = Money::of(0, $sale->currency, $ctx4);

        // Resolve the distributor for this sale's zone
        $distributor = $this->resolveDistributorForSale($sale);

        foreach ($items as $item) {
            /** @var \App\Models\SaleItem $item */
            $product  = $item->product;
            // Normalise subtotal to CustomContext(4) to match the accumulators.
            $subtotalRaw = $item->subtotal;
            $subtotal    = $subtotalRaw !== null
                ? Money::of($subtotalRaw->getAmount(), $subtotalRaw->getCurrency(), $ctx4, RoundingMode::HALF_UP)
                : Money::of(0, $sale->currency, $ctx4);

            $costPerBoxRaw = $this->resolvePreferredCostPerBox($distributor, $product);

            // Cost must be in the same currency as the sale subtotal.
            // If currencies differ (e.g. cost configured in USD, sale in ARS)
            // we cannot do arithmetic — throw to force correct configuration.
            if ($costPerBoxRaw->getCurrency()->getCurrencyCode() !== $sale->currency) {
                throw new RuntimeException(sprintf(
                    'Currency mismatch: preferred cost for product %s is in %s but sale currency is %s. '
                    . 'Configure preferred cost in %s.',
                    $product->id,
                    $costPerBoxRaw->getCurrency()->getCurrencyCode(),
                    $sale->currency,
                    $sale->currency,
                ));
            }

            // Normalise cost to CustomContext(4) so all arithmetic shares the same context.
            $costPerBox = Money::of($costPerBoxRaw->getAmount(), $costPerBoxRaw->getCurrency(), $ctx4, RoundingMode::HALF_UP);
            $itemCost   = $costPerBox->multipliedBy($item->quantity_boxes, RoundingMode::HALF_UP);

            $totalRevenue = $totalRevenue->plus($subtotal);
            $totalCost    = $totalCost->plus($itemCost);
        }

        return $totalRevenue->minus($totalCost);
    }

    /**
     * Aggregate gross margin for a Distributor over a CarbonPeriod.
     *
     * Iterates over all delivered sales in the Distributor's zone(s) within
     * the period whose currency matches the requested currency, and sums the
     * per-sale margins.
     *
     * This is intentionally computed by iterating Eloquent sales (not raw SQL)
     * so that resolvePreferredCostPerBox is applied consistently. For very
     * large result sets the caller should page this or run it as a queued job.
     *
     * @param User         $distributor The Distributor user.
     * @param CarbonPeriod $period      Date range (inclusive).
     * @param string       $currency    'ARS' or 'USD'.
     * @return Money                    Aggregated gross margin.
     */
    public function marginInPeriod(User $distributor, CarbonPeriod $period, string $currency): Money
    {
        $accumulated = Money::of(0, $currency, new CustomContext(4));

        Sale::query()
            ->with(['items.product'])
            ->where('status', SaleStatus::Delivered)
            ->where('currency', $currency)
            ->whereHas('zone', fn (Builder $q) =>
                $q->where('distributor_id', $distributor->id)
            )
            ->whereBetween('sale_date', [
                $period->getStartDate()->toDateString(),
                $period->getEndDate()->toDateString(),
            ])
            ->chunkById(50, function ($sales) use (&$accumulated, $distributor): void {
                foreach ($sales as $sale) {
                    try {
                        $margin      = $this->marginForSale($sale);
                        $accumulated = $accumulated->plus($margin);
                    } catch (RuntimeException) {
                        // Currency mismatch on individual sale — skip and log.
                        // In production this should surface via Sentry.
                        report(new RuntimeException(
                            "Skipped margin calculation for sale {$sale->id} due to currency mismatch."
                        ));
                    }
                }
            });

        return $accumulated;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function productBasePrice(Product $product): Money
    {
        // Product stores base_price as compound columns (base_price_amount, base_price_currency)
        // hydrated via MoneyCast. Access the cast virtual attribute.
        $money = $product->base_price;

        if (! $money instanceof Money) {
            throw new RuntimeException("Product {$product->id} has no base_price configured.");
        }

        return $money;
    }

    private function applyDiscount(Money $basePrice, BigDecimal $discountFraction): Money
    {
        // discountFraction is e.g. 0.2000 for 20% off.
        // Cost = basePrice × (1 − discountFraction)
        $multiplier = BigDecimal::one()->minus($discountFraction);

        return $basePrice->multipliedBy($multiplier, RoundingMode::HALF_UP);
    }

    /**
     * Resolves which Distributor to use for a given sale.
     *
     * If the sale has delegated_delivery, the cost is attributed to the
     * delegated_distributor. Otherwise, we look up the zone's distributor.
     */
    private function resolveDistributorForSale(Sale $sale): User
    {
        if ($sale->delegated_delivery && $sale->delegated_distributor_id !== null) {
            return $sale->delegatedDistributor ?? User::findOrFail($sale->delegated_distributor_id);
        }

        // Look up the zone's current distributor
        $zone = $sale->zone()->with([])->first();

        if ($zone === null || $zone->distributor_id === null) {
            // Direct zone — no distributor; return a dummy user with no preferred costs configured.
            // This results in zero margin, which is correct (Dermacells takes 100% margin on direct zones).
            // We fake it with the sale's seller — no preferred cost will be found and we fall back to base price.
            return User::findOrFail($sale->seller_id);
        }

        return User::findOrFail($zone->distributor_id);
    }
}
