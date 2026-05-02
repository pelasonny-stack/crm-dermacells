<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Actions\Stock\RollbackStockOnCancelAction;
use App\Domain\Payments\Services\CreditBalanceService;
use App\Enums\SaleStatus;
use App\Exceptions\InvoiceNcRequiredException;
use App\Exceptions\InvalidSaleTransitionException;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SalesStatusHistory;
use App\Models\User;
use App\StateMachines\SaleTransitionGuard;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a sale according to the rules in §5.6.
 *
 * Rules:
 *   - If an invoice exists → throw InvoiceNcRequiredException (409).
 *     Controller surfaces this as code=INVOICE_NC_REQUIRED.
 *   - If the sale has payments and actor is not Director → throw 403.
 *   - Seller/Distributor may cancel only their own sales (no payments, no invoice).
 *   - Director may cancel any sale (still blocked by invoice existence).
 *   - Stock is returned to the appropriate stock pool via Phase 4's
 *     RollbackStockOnCancelAction (only for confirmed or delivered sales).
 *   - When cancelling with payments but no invoice: the amount goes to customer
 *     credit balance (Phase 7 placeholder — no-op here).
 *
 * All steps inside a single DB::transaction().
 */
final class CancelSaleAction
{
    public function __construct(
        private readonly RollbackStockOnCancelAction $rollbackStock,
        private readonly CreditBalanceService $creditBalanceService,
    ) {}

    /**
     * @throws InvoiceNcRequiredException   (409 — controller maps to INVOICE_NC_REQUIRED)
     * @throws InvalidSaleTransitionException (422 or 403 depending on the violation)
     */
    public function execute(Sale $sale, User $actor, string $reason): Sale
    {
        return DB::transaction(function () use ($sale, $actor, $reason): Sale {
            $fromStatus = $sale->status;

            $sale->refresh();
            $sale->load('items');

            // SaleTransitionGuard handles: invoice check, ownership, payments check
            (new SaleTransitionGuard($sale, $actor))->assertCanTransitionTo(SaleStatus::Cancelled);

            // Rollback stock only if the sale was confirmed or delivered
            // (draft cancellations have no reservation to roll back)
            if (in_array($sale->status, [SaleStatus::Confirmed, SaleStatus::Delivered], true)) {
                $stockOwnerId = $sale->delegated_delivery
                    ? $sale->delegated_distributor_id
                    : $sale->seller_id;

                foreach ($sale->items as $item) {
                    $this->rollbackStock->execute(
                        productId: $item->product_id,
                        sellerId: $stockOwnerId,
                        quantityBoxes: $item->quantity_boxes,
                        saleId: $sale->id,
                        actorId: $actor->id,
                    );
                }
            }

            // Phase 7: if sale had non-reversed payments and no invoice, generate saldo a favor.
            // If an invoice exists, InvoiceNcRequiredException was already thrown above by
            // SaleTransitionGuard. The Phase 6 agent catches that exception and calls
            // creditFromCancellation AFTER the NC is emitted successfully.
            $this->creditFromCancellation($sale);

            $sale->update([
                'status'              => SaleStatus::Cancelled,
                'cancellation_reason' => $reason,
                'cancelled_by'        => $actor->id,
                'cancelled_at'        => now(),
            ]);

            SalesStatusHistory::create([
                'sale_id'     => $sale->id,
                'from_status' => $fromStatus->value,
                'to_status'   => SaleStatus::Cancelled->value,
                'changed_by'  => $actor->id,
                'changed_at'  => now(),
                'note'        => $reason,
            ]);

            return $sale->refresh();
        });
    }

    /**
     * Creates customer_credit_balances rows for each active payment currency
     * when a sale is cancelled without an invoice.
     *
     * Called from inside execute() for the no-invoice case.
     * TODO Phase 6: the Phase 6 agent should also call this method (via
     *   CancelSaleAction::creditFromCancellation) AFTER a successful NC emission
     *   in Xubio, once the InvoiceNcRequiredException has been handled.
     *
     * Per §7.4, payments in ARS and USD are tracked separately. We generate
     * one credit balance row per distinct currency found in the sale's active
     * payments.
     */
    private function creditFromCancellation(Sale $sale): void
    {
        // Eager-load payments if not already loaded
        $sale->loadMissing('payments');

        // Only non-reversed payments create credit balances
        $activePayments = $sale->payments->filter(fn (Payment $p): bool => ! $p->reversed);

        if ($activePayments->isEmpty()) {
            return;
        }

        // Group by currency and sum
        $byCurrency = $activePayments->groupBy('amount_currency');

        foreach ($byCurrency as $currency => $paymentsInCurrency) {
            $totalString = $paymentsInCurrency->sum(
                fn (Payment $p): float => (float) (string) $p->amount->getAmount()
            );

            if ($totalString <= 0) {
                continue;
            }

            $money = Money::of((string) $totalString, $currency);

            $this->creditBalanceService->creditFromCancelledSale(
                sale: $sale,
                amount: $money,
                creditNoteId: null, // null = no invoice. Phase 6 passes NC id.
            );
        }
    }
}
