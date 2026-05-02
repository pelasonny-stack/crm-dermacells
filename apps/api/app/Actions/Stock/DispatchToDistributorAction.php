<?php

declare(strict_types=1);

namespace App\Actions\Stock;

use App\Enums\StockMovementType;
use App\Exceptions\StockInsufficientException;
use App\Models\CentralStock;
use App\Models\DistributorStock;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;

/**
 * Dispatches boxes from central stock to a distributor (§4.3).
 *
 * Director only. Validates available >= quantity before decrementing.
 * Throws StockInsufficientException (→ controller maps to 422) on shortfall.
 *
 * Operations:
 *   1. Decrement central_stock.total_dispatched (generated `available` adjusts automatically).
 *   2. Upsert distributor_stock (total_received + available).
 *   3. Create StockMovement('dispatch_to_dist').
 */
final class DispatchToDistributorAction
{
    /**
     * @param  string      $productId      UUID of the product.
     * @param  string      $distributorId  UUID of the target distributor user.
     * @param  int         $quantityBoxes  Boxes to dispatch.
     * @param  string|null $referenceDoc   Internal dispatch doc number.
     * @return array{movement: StockMovement, distributorStock: DistributorStock, centralStock: CentralStock}
     */
    public function execute(
        string $productId,
        string $distributorId,
        int $quantityBoxes,
        ?string $referenceDoc = null,
    ): array {
        /** @var User $actor */
        $actor = Auth::user();

        if (! $actor->role->isDirector()) {
            throw new UnauthorizedException('Only Directors can dispatch from central stock.');
        }

        return DB::transaction(function () use ($actor, $productId, $distributorId, $quantityBoxes, $referenceDoc): array {
            // Lock central_stock row for this product to prevent race conditions
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

            // Decrement central: increment dispatched (available is generated)
            $centralStock->increment('total_dispatched', $quantityBoxes);
            $centralStock->refresh();

            // Upsert distributor_stock
            $distributorStock = DistributorStock::firstOrCreate(
                ['distributor_id' => $distributorId, 'product_id' => $productId],
                ['total_received' => 0, 'total_redistributed' => 0, 'reserved' => 0, 'available' => 0, 'minimum_stock' => 0],
            );

            $distributorStock->increment('total_received', $quantityBoxes);
            $distributorStock->increment('available', $quantityBoxes);
            $distributorStock->refresh();

            // Create the movement record
            $movement = StockMovement::create([
                'movement_type'    => StockMovementType::DispatchToDist,
                'product_id'       => $productId,
                'from_entity_type' => 'central',
                'from_entity_id'   => null,
                'to_entity_type'   => 'distributor',
                'to_entity_id'     => $distributorId,
                'quantity_boxes'   => $quantityBoxes,
                'quantity_units'   => 0,
                'lot_id'           => null,
                'reference_doc'    => $referenceDoc,
                'sale_id'          => null,
                'created_by'       => $actor->id,
                'created_at'       => now(),
            ]);

            return compact('movement', 'distributorStock', 'centralStock');
        });
    }
}
