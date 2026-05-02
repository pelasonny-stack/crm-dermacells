<?php

declare(strict_types=1);

namespace App\Actions\Stock;

use App\Enums\StockMovementType;
use App\Exceptions\StockInsufficientException;
use App\Models\DistributorStock;
use App\Models\SellerStock;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;

/**
 * Redistributes boxes from a distributor to a seller in their zone (§4.4).
 *
 * Distributor only. Throws StockInsufficientException if the distributor does
 * not have enough available stock.
 *
 * Operations (all in one transaction):
 *   1. Validate distributor_stock.available >= quantity.
 *   2. Decrement distributor_stock.available and increment total_redistributed.
 *   3. Upsert seller_stock.boxes.
 *   4. Create StockMovement('redistribution').
 */
final class RedistributeFromDistributorAction
{
    /**
     * @param  string      $productId     UUID of the product.
     * @param  string      $sellerId      UUID of the target seller user.
     * @param  int         $quantityBoxes Boxes to redistribute.
     * @param  string|null $referenceDoc  Optional reference document.
     * @return array{movement: StockMovement, sellerStock: SellerStock, distributorStock: DistributorStock}
     */
    public function execute(
        string $productId,
        string $sellerId,
        int $quantityBoxes,
        ?string $referenceDoc = null,
    ): array {
        /** @var User $actor */
        $actor = Auth::user();

        if (! $actor->role->isDistributor()) {
            throw new UnauthorizedException('Only Distributors can redistribute stock to sellers.');
        }

        return DB::transaction(function () use ($actor, $productId, $sellerId, $quantityBoxes, $referenceDoc): array {
            $distributorStock = DistributorStock::where('distributor_id', $actor->id)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            $available = $distributorStock?->available ?? 0;

            if ($available < $quantityBoxes) {
                throw new StockInsufficientException(
                    productId: $productId,
                    requested: $quantityBoxes,
                    available: $available,
                    sourceType: 'distributor',
                    sourceId: $actor->id,
                );
            }

            $distributorStock->decrement('available', $quantityBoxes);
            $distributorStock->increment('total_redistributed', $quantityBoxes);
            $distributorStock->refresh();

            $sellerStock = SellerStock::firstOrCreate(
                ['seller_id' => $sellerId, 'product_id' => $productId],
                ['boxes' => 0, 'loose_units' => 0, 'reserved_boxes' => 0, 'reserved_units' => 0, 'minimum_stock' => 0],
            );

            $sellerStock->increment('boxes', $quantityBoxes);
            $sellerStock->refresh();

            $movement = StockMovement::create([
                'movement_type'    => StockMovementType::Redistribution,
                'product_id'       => $productId,
                'from_entity_type' => 'distributor',
                'from_entity_id'   => $actor->id,
                'to_entity_type'   => 'seller',
                'to_entity_id'     => $sellerId,
                'quantity_boxes'   => $quantityBoxes,
                'quantity_units'   => 0,
                'lot_id'           => null,
                'reference_doc'    => $referenceDoc,
                'sale_id'          => null,
                'created_by'       => $actor->id,
                'created_at'       => now(),
            ]);

            return compact('movement', 'sellerStock', 'distributorStock');
        });
    }
}
