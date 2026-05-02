<?php

declare(strict_types=1);

namespace App\Actions\Stock;

use App\Enums\StockMovementType;
use App\Models\SellerStock;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * Commits reserved stock when a sale transitions Confirmada → Entregada (§5.5).
 *
 * Called by Phase 5 DeliverSaleAction. Single responsibility: atomic UPDATE on
 * seller_stock (decrement both boxes AND reserved_boxes) + INSERT a
 * StockMovement('sale_deliver').
 *
 * The reserved amount was already deducted from available at reservation time;
 * here we subtract from the actual box count making the deduction permanent.
 */
class CommitStockOnDeliverAction
{
    /**
     * @param  string $productId     UUID of the product.
     * @param  string $sellerId      UUID of the seller whose stock is committed.
     * @param  int    $quantityBoxes Number of boxes consumed by the delivery.
     * @param  string $saleId        UUID of the delivered sale.
     * @param  string $actorId       UUID of the user performing the delivery.
     */
    public function execute(
        string $productId,
        string $sellerId,
        int $quantityBoxes,
        string $saleId,
        string $actorId,
    ): StockMovement {
        return DB::transaction(function () use ($productId, $sellerId, $quantityBoxes, $saleId, $actorId): StockMovement {
            $stock = SellerStock::where('seller_id', $sellerId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if ($stock !== null) {
                $stock->decrement('boxes', $quantityBoxes);
                $stock->decrement('reserved_boxes', $quantityBoxes);
                $stock->refresh();
            }

            return StockMovement::create([
                'movement_type'    => StockMovementType::SaleDeliver,
                'product_id'       => $productId,
                'from_entity_type' => 'reserved',
                'from_entity_id'   => $saleId,
                'to_entity_type'   => 'sold',
                'to_entity_id'     => $saleId,
                'quantity_boxes'   => $quantityBoxes,
                'quantity_units'   => 0,
                'sale_id'          => $saleId,
                'created_by'       => $actorId,
                'created_at'       => now(),
            ]);
        });
    }
}
