<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Actions\Stock\ReserveStockOnSaleConfirmAction;
use App\Domain\Authorizations\Services\AuthorizationService;
use App\Enums\SaleStatus;
use App\Exceptions\OperationBlockedByPendingAuthorizationException;
use App\Exceptions\StockInsufficientException;
use App\Models\Sale;
use App\Models\SalesStatusHistory;
use App\Models\User;
use App\StateMachines\SaleTransitionGuard;
use Illuminate\Support\Facades\DB;

/**
 * Confirms a draft sale, reserving stock for each line item.
 *
 * Per §4.5: stock validation BLOCKS at confirmation (unlike draft creation
 * which only warns). If any item lacks sufficient stock, the action throws
 * StockInsufficientException and the sale remains in 'draft'.
 *
 * Flow:
 *   1. Assert transition is valid (SaleTransitionGuard)
 *   2. For each sale item, call Phase 4's ReserveStockOnSaleConfirmAction
 *      (reserves from seller_stock or distributor_stock depending on delegated_delivery)
 *   3. Transition status to 'confirmed'
 *   4. Record SalesStatusHistory entry
 *
 * All steps run inside a single DB::transaction().
 */
final class ConfirmSaleAction
{
    public function __construct(
        private readonly ReserveStockOnSaleConfirmAction $reserveStock,
        private readonly AuthorizationService $authorizationService,
    ) {}

    /**
     * @throws \App\Exceptions\InvalidSaleTransitionException
     * @throws StockInsufficientException
     * @throws OperationBlockedByPendingAuthorizationException
     */
    public function execute(Sale $sale, User $actor, ?string $note = null): Sale
    {
        return DB::transaction(function () use ($sale, $actor, $note): Sale {
            $sale->lockForUpdate()->refresh();
            $sale->load('items');

            // 1. State machine guard
            (new SaleTransitionGuard($sale, $actor))->assertCanTransitionTo(SaleStatus::Confirmed);

            // Phase 11 — block confirmation if any pending authorization exists (§13.2)
            $this->assertNoPendingAuthorizations($sale);

            // 2. Reserve stock for each item via Phase 4 action
            // For delegated_delivery: stock comes from the delegated_distributor (treated as seller_id).
            $stockOwnerId = $sale->delegated_delivery
                ? $sale->delegated_distributor_id
                : $sale->seller_id;

            foreach ($sale->items as $item) {
                $this->reserveStock->execute(
                    productId: $item->product_id,
                    sellerId: $stockOwnerId,
                    quantityBoxes: $item->quantity_boxes,
                    saleId: $sale->id,
                    actorId: $actor->id,
                );
            }

            // 3. Transition
            $sale->update(['status' => SaleStatus::Confirmed]);

            // 4. History
            SalesStatusHistory::create([
                'sale_id'     => $sale->id,
                'from_status' => SaleStatus::Draft->value,
                'to_status'   => SaleStatus::Confirmed->value,
                'changed_by'  => $actor->id,
                'changed_at'  => now(),
                'note'        => $note,
            ]);

            return $sale->refresh();
        });
    }

    /**
     * Phase 11 guard: throw if the sale has any pending authorization request.
     *
     * Called inside the wrapping DB::transaction so that even in race conditions
     * (Director approves at the exact same instant) the lock on the sale row
     * prevents a double-confirm.
     *
     * @throws OperationBlockedByPendingAuthorizationException
     */
    private function assertNoPendingAuthorizations(Sale $sale): void
    {
        if ($this->authorizationService->pendingForSale($sale)) {
            throw new OperationBlockedByPendingAuthorizationException($sale->id);
        }
    }
}
