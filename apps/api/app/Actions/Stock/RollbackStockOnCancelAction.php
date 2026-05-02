<?php

declare(strict_types=1);

namespace App\Actions\Stock;

use App\Enums\StockMovementType;
use App\Models\SellerStock;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * Rolls back reserved stock when a confirmed sale is cancelled (§5.5, §5.6).
 *
 * Called by Phase 5 CancelSaleAction (for Confirmada state only; Borrador
 * cancellations have no stock reservation to roll back).
 *
 * Single responsibility: atomic UPDATE on seller_stock (decrement reserved_boxes,
 * restoring the boxes to available) + INSERT a StockMovement('sale_cancel').
 */
final class RollbackStockOnCancelAction
{
    /**
     * @param  string $productId     UUID of the product.
     * @param  string $sellerId      UUID of the seller whose reservation is rolled back.
     * @param  int    $quantityBoxes Number of reserved boxes to release.
     * @param  string $saleId        UUID of the cancelled sale.
     * @param  string $actorId       UUID of the user cancelling the sale.
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
                $stock->decrement('reserved_boxes', $quantityBoxes);
                $stock->refresh();
            }

            return StockMovement::create([
                'movement_type'    => StockMovementType::SaleCancel,
                'product_id'       => $productId,
                'from_entity_type' => 'reserved',
                'from_entity_id'   => $saleId,
                'to_entity_type'   => 'seller',
                'to_entity_id'     => $sellerId,
                'quantity_boxes'   => $quantityBoxes,
                'quantity_units'   => 0,
                'sale_id'          => $saleId,
                'created_by'       => $actorId,
                'created_at'       => now(),
            ]);
        });
    }
}
