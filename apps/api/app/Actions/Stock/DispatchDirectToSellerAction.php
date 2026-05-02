<?php

declare(strict_types=1);

namespace App\Actions\Stock;

use App\Enums\StockMovementType;
use App\Exceptions\StockInsufficientException;
use App\Models\CentralStock;
use App\Models\SellerStock;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;

/**
 * Dispatches boxes directly from central stock to a seller in a zona directa (§4.3).
 *
 * Director only. Used when the seller's zone has no assigned distributor.
 * Throws StockInsufficientException on shortfall.
 *
 * Operations:
 *   1. Validate and decrement central_stock.total_dispatched.
 *   2. Upsert seller_stock (boxes).
 *   3. Create StockMovement('dispatch_to_seller').
 */
final class DispatchDirectToSellerAction
{
    /**
     * @param  string      $productId    UUID of the product.
     * @param  string      $sellerId     UUID of the target seller user.
     * @param  int         $quantityBoxes Boxes to dispatch.
     * @param  string|null $referenceDoc  Internal dispatch document.
     * @return array{movement: StockMovement, sellerStock: SellerStock, centralStock: CentralStock}
     */
    public function execute(
        string $productId,
        string $sellerId,
        int $quantityBoxes,
        ?string $referenceDoc = null,
    ): array {
        /** @var User $actor */
        $actor = Auth::user();

        if (! $actor->role->isDirector()) {
            throw new UnauthorizedException('Only Directors can dispatch directly from central stock.');
        }

        return DB::transaction(function () use ($actor, $productId, $sellerId, $quantityBoxes, $referenceDoc): array {
            $centralStock = CentralStock::where('product_id', $productId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($centralStock->available < $quantityBoxes) {
                throw new StockInsufficientException(
                    productId: $productId,
                    requested: $quantityBoxes,
                    available: $centralStock->available,
                    sourceType: 'central',
                );
            }

            $centralStock->increment('total_dispatched', $quantityBoxes);
            $centralStock->refresh();

            $sellerStock = SellerStock::firstOrCreate(
                ['seller_id' => $sellerId, 'product_id' => $productId],
                ['boxes' => 0, 'loose_units' => 0, 'reserved_boxes' => 0, 'reserved_units' => 0, 'minimum_stock' => 0],
            );

            $sellerStock->increment('boxes', $quantityBoxes);
            $sellerStock->refresh();

            $movement = StockMovement::create([
                'movement_type'    => StockMovementType::DispatchToSeller,
                'product_id'       => $productId,
                'from_entity_type' => 'central',
                'from_entity_id'   => null,
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

            return compact('movement', 'sellerStock', 'centralStock');
        });
    }
}
