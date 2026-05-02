<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Actions\Stock\CommitStockOnDeliverAction;
use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\SalesStatusHistory;
use App\Models\User;
use App\StateMachines\SaleTransitionGuard;
use Illuminate\Support\Facades\DB;

/**
 * Delivers a confirmed sale, committing reserved stock as sold.
 *
 * For sales with delegated_delivery=true (§5.7), the deliverer must be
 * the Distributor of the sale's zone (or a Director). SaleTransitionGuard
 * enforces this role requirement.
 *
 * Flow:
 *   1. Assert transition is valid (SaleTransitionGuard — includes delegated check)
 *   2. For each item, commit stock via Phase 4's CommitStockOnDeliverAction
 *      (deducts from seller_stock or distributor_stock per delegated_delivery flag)
 *   3. Transition status to 'delivered'
 *   4. Record SalesStatusHistory entry
 *
 * All steps inside a single DB::transaction().
 */
final class DeliverSaleAction
{
    public function __construct(
        private readonly CommitStockOnDeliverAction $commitStock,
    ) {}

    /**
     * @throws \App\Exceptions\InvalidSaleTransitionException
     */
    public function execute(Sale $sale, User $actor, ?string $note = null): Sale
    {
        return DB::transaction(function () use ($sale, $actor, $note): Sale {
            $sale->lockForUpdate()->refresh();
            $sale->load('items');

            // 1. State machine guard (includes delegated_delivery role check)
            (new SaleTransitionGuard($sale, $actor))->assertCanTransitionTo(SaleStatus::Delivered);

            // 2. Commit stock for each item
            // For delegated sales: stock is debited from the Distributor's seller_stock record
            $stockOwnerId = $sale->delegated_delivery
                ? $sale->delegated_distributor_id
                : $sale->seller_id;

            foreach ($sale->items as $item) {
                $this->commitStock->execute(
                    productId: $item->product_id,
                    sellerId: $stockOwnerId,
                    quantityBoxes: $item->quantity_boxes,
                    saleId: $sale->id,
                    actorId: $actor->id,
                );
            }

            // 3. Transition
            $sale->update(['status' => SaleStatus::Delivered]);

            // 4. History
            SalesStatusHistory::create([
                'sale_id'     => $sale->id,
                'from_status' => SaleStatus::Confirmed->value,
                'to_status'   => SaleStatus::Delivered->value,
                'changed_by'  => $actor->id,
                'changed_at'  => now(),
                'note'        => $note,
            ]);

            return $sale->refresh();
        });
    }
}
