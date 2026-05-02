<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Enums\PartialReturnStatus;
use App\Models\PartialReturn;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Brick\Money\Money;
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

            // Compute refund amount
            $refund = $this->computeRefund($saleItem, $quantityBoxes, $quantityUnits);

            return PartialReturn::create([
                'sale_id'      => $sale->id,
                'sale_item_id' => $saleItem->id,
                'initiated_by' => $actor->id,
                'confirmed_by' => null,
                'status'       => PartialReturnStatus::PendingDirectorConfirmation,
                'quantity_boxes' => $quantityBoxes,
                'quantity_units' => $quantityUnits,
                'refund_amount'  => (string) $refund->getAmount(),
                'refund_currency' => $refund->getCurrency()->getCurrencyCode(),
                'reason'         => $data['reason'] ?? null,
                'initiated_at'   => now(),
                'confirmed_at'   => null,
            ]);
        });
    }

    /**
     * Proportional refund calculation.
     *
     * unit_price covers one full box (units_per_box units).
     * Loose units are priced at unit_price / units_per_box each.
     */
    private function computeRefund(
        SaleItem $saleItem,
        int $quantityBoxes,
        int $quantityUnits,
    ): Money {
        $unitPrice = $saleItem->unit_price;
        $currency  = $saleItem->unit_price_currency;

        $boxRefund  = $unitPrice->multipliedBy($quantityBoxes);

        // Loose-unit price = unit_price / units_per_box (integer division safe here
        // because we only return full units from a box whose price was set for the full box)
        $unitsPerBox       = $saleItem->product?->units_per_box ?? 5;
        $unitLooseRefund   = $quantityUnits > 0
            ? $unitPrice->dividedBy($unitsPerBox, \Brick\Math\RoundingMode::HALF_UP)->multipliedBy($quantityUnits)
            : Money::of(0, $currency);

        return $boxRefund->plus($unitLooseRefund);
    }
}
