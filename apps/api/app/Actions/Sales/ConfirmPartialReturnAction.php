<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Domain\Payments\Services\CreditBalanceService;
use App\Enums\PartialReturnStatus;
use App\Exceptions\InvoiceNcRequiredException;
use App\Models\PartialReturn;
use App\Models\User;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Director confirms a partial return (§5.8 — step 2 of 2).
 *
 * If the parent sale has an invoice:
 *   → throws InvoiceNcRequiredException
 *   → status transitions to 'awaiting_credit_note'
 *   → Phase 6 will catch this and trigger the Xubio NC flow.
 *
 * If no invoice:
 *   → stock is returned to the current assigned Seller's stock
 *   → refund_amount is posted to customer credit balance (Phase 7 placeholder)
 *   → status = 'applied'
 *
 * All steps inside a single DB::transaction().
 */
final class ConfirmPartialReturnAction
{
    public function __construct(
        private readonly CreditBalanceService $creditBalanceService,
    ) {}

    /**
     * @throws \App\Exceptions\InvoiceNcRequiredException
     * @throws InvalidArgumentException if return is not in pending state
     */
    public function execute(PartialReturn $return, User $director): PartialReturn
    {
        return DB::transaction(function () use ($return, $director): PartialReturn {
            if (! $return->isPending()) {
                throw new InvalidArgumentException(
                    "Cannot confirm a partial return in status [{$return->status->value}]."
                );
            }

            $sale = $return->sale;

            // If invoice exists, block until NC is issued
            if ($sale->hasInvoice()) {
                $return->update([
                    'status'       => PartialReturnStatus::AwaitingCreditNote,
                    'confirmed_by' => $director->id,
                    'confirmed_at' => now(),
                ]);

                throw new InvoiceNcRequiredException(
                    'Partial return confirmed but sale has an invoice. Issue a credit note in Xubio to complete the return.'
                );
            }

            // No invoice: apply immediately
            $this->returnStockToSeller($return, $sale);

            // Phase 7: post the refund amount as saldo a favor on the customer's account.
            // The refund currency matches the partial return's refund_currency column.
            $this->creditFromCancellation($return, $sale);

            $return->update([
                'status'       => PartialReturnStatus::Applied,
                'confirmed_by' => $director->id,
                'confirmed_at' => now(),
            ]);

            return $return->refresh();
        });
    }

    /**
     * Creates a credit balance row for the partial return refund amount (§5.8, §7.5).
     *
     * Called from execute() for the no-invoice path.
     * TODO Phase 6: when the invoice path completes (NC emitted), the Phase 6
     * agent should call creditBalanceService::creditFromCancelledSale directly
     * with the NC id, bypassing this method.
     */
    private function creditFromCancellation(PartialReturn $return, \App\Models\Sale $sale): void
    {
        $refundAmount = $return->refund;

        if ($refundAmount === null) {
            return;
        }

        // Ensure positive amount
        if ($refundAmount->isZero() || $refundAmount->isNegative()) {
            return;
        }

        $this->creditBalanceService->creditFromCancelledSale(
            sale: $sale,
            amount: $refundAmount,
            creditNoteId: null,
        );
    }

    /**
     * Returns stock to the Seller currently assigned to the sale's customer.
     * Phase 4 implementation placeholder.
     */
    private function returnStockToSeller(PartialReturn $return, \App\Models\Sale $sale): void
    {
        // Phase 4 will replace this with:
        //
        // $currentSellerId = $sale->customer->assigned_seller_id;
        //
        // $stock = SellerStock::where('product_id', $return->saleItem->product_id)
        //     ->where('user_id', $currentSellerId)
        //     ->lockForUpdate()->firstOrFail();
        //
        // $stock->boxes_available += $return->quantity_boxes;
        // $stock->units_available += $return->quantity_units;
        // $stock->save();
        //
        // StockMovement::create([...]);  // type = 'partial_return'
    }
}
