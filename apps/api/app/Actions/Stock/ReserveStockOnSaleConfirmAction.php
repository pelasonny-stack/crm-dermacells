<?php

declare(strict_types=1);

namespace App\Actions\Stock;

use App\Enums\StockMovementType;
use App\Exceptions\StockInsufficientException;
use App\Models\SellerStock;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Reserves seller stock when a sale transitions Borrador → Confirmada (§5.5).
 *
 * Called by Phase 5 ConfirmSaleAction. Single responsibility: atomic UPDATE
 * on seller_stock (increment reserved_boxes, decrement available) + INSERT
 * a StockMovement('sale_reserve').
 *
 * Throws StockInsufficientException if the seller does not have enough available
 * boxes — this is the hard block on Confirmar (§4.5).
 */
class ReserveStockOnSaleConfirmAction
{
    /**
     * @param  string $productId    UUID of the product.
     * @param  string $sellerId     UUID of the seller whose stock is reserved.
     * @param  int    $quantityBoxes Number of boxes to reserve.
     * @param  string $saleId       UUID of the sale triggering the reservation.
     * @param  string $actorId      UUID of the user performing the confirmation.
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

            $available = $stock ? $stock->availableBoxes() : 0;

            if ($available < $quantityBoxes) {
                throw new StockInsufficientException(
                    productId: $productId,
                    requested: $quantityBoxes,
                    available: $available,
                    sourceType: 'seller',
                    sourceId: $sellerId,
                );
            }

            $stock->increment('reserved_boxes', $quantityBoxes);
            $stock->refresh();

            return StockMovement::create([
                'movement_type'    => StockMovementType::SaleReserve,
                'product_id'       => $productId,
                'from_entity_type' => 'seller',
                'from_entity_id'   => $sellerId,
                'to_entity_type'   => 'reserved',
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
