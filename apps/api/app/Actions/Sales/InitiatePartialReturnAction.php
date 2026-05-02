<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Enums\PartialReturnStatus;
use App\Models\PartialReturn;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Initiates a partial product return (§5.8 — step 1 of 2).
 *
 * Only the Seller assigned to the sale's customer may initiate a return.
 * The Director confirms it in ConfirmPartialReturnAction.
 *
 * The refund amount is computed at initiation time as:
 *   (quantity_boxes × unit_price) + (quantity_units × unit_price_per_unit)
 * where unit_price_per_unit = unit_price / product.units_per_box.
 *
 * The resulting PartialReturn is created with status 'pending_director_confirmation'.
 * No stock or balance changes occur until the Director confirms.
 */
final class InitiatePartialReturnAction
{
    /**
     * @param array{quantity_boxes: int, quantity_units: int, reason: ?string} $data
     * @throws InvalidArgumentException if quantities exceed the sale item quantities
     */
    public function execute(
        Sale $sale,
        SaleItem $saleItem,
        User $actor,
        array $data,
    ): PartialReturn {
        return DB::transaction(function () use ($sale, $saleItem, $actor, $data): PartialReturn {
            $quantityBoxes = (int) ($data['quantity_boxes'] ?? 0);
            $quantityUnits = (int) ($data['quantity_units'] ?? 0);

            // Validate return quantities do not exceed sold quantities
            if (
                $quantityBoxes > $saleItem->quantity_boxes
                || $quantityUnits > $saleItem->quantity_units
            ) {
                throw new InvalidArgumentException(
                    'Return quantities cannot exceed the quantities in the original sale item.'
                );
            }

            // Compute refund amount (returns BigDecimal amount string + currency code)
            [$refundAmount, $refundCurrency] = $this->computeRefund($saleItem, $quantityBoxes, $quantityUnits);

            return PartialReturn::create([
                'sale_id'      => $sale->id,
                'sale_item_id' => $saleItem->id,
                'initiated_by' => $actor->id,
                'confirmed_by' => null,
                'status'       => PartialReturnStatus::PendingDirectorConfirmation,
                'quantity_boxes' => $quantityBoxes,
                'quantity_units' => $quantityUnits,
                'refund_amount'  => $refundAmount,
                'refund_currency' => $refundCurrency,
                'reason'         => $data['reason'] ?? null,
                'initiated_at'   => now(),
                'confirmed_at'   => null,
            ]);
        });
    }

    /**
     * Proportional refund calculation.
     *
     * Uses BigDecimal directly to preserve the 4-decimal NUMERIC(18,4) precision.
     * Returns [amountString, currencyCode] to bypass Money's currency-scale normalization.
     *
     * @return array{0: string, 1: string}  [amount with 4 decimals, currency code]
     */
    private function computeRefund(
        SaleItem $saleItem,
        int $quantityBoxes,
        int $quantityUnits,
    ): array {
        $currency = $saleItem->unit_price_currency;
        $scale    = 4; // NUMERIC(18,4)

        $unitPriceAmount = \Brick\Math\BigDecimal::of((string) $saleItem->unit_price_amount);

        $boxRefundAmount = $unitPriceAmount->multipliedBy($quantityBoxes)->toScale($scale);

        if ($quantityUnits === 0) {
            return [(string) $boxRefundAmount, $currency];
        }

        $unitsPerBox     = $saleItem->product?->units_per_box ?? 5;
        $looseUnitAmount = $unitPriceAmount
            ->dividedBy($unitsPerBox, $scale, \Brick\Math\RoundingMode::HALF_UP)
            ->multipliedBy($quantityUnits)
            ->toScale($scale);

        $total = $boxRefundAmount->plus($looseUnitAmount)->toScale($scale);

        return [(string) $total, $currency];
    }
}
