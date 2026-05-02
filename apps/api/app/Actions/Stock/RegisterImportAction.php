<?php

declare(strict_types=1);

namespace App\Actions\Stock;

use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Exceptions\StockInsufficientException;
use App\Models\CentralStock;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;

/**
 * Registers a new product importation into central stock (bodega Dermacells).
 *
 * Director only (§4.2). Creates:
 *   1. A StockLot row for traceability.
 *   2. A StockMovement of type 'import'.
 *   3. Upserts the CentralStock row, incrementing total_imported.
 *
 * All three writes occur inside a single DB transaction.
 */
final class RegisterImportAction
{
    /**
     * @param  string      $productId      UUID of the product being imported.
     * @param  int         $quantityBoxes  Number of boxes received.
     * @param  string      $importDate     ISO date string (Y-m-d).
     * @param  string      $lotNumber      Remito / lot identifier.
     * @param  string|null $supplier       Supplier name (optional).
     * @param  string|null $expiryDate     ISO date string (Y-m-d), optional.
     * @param  string|null $referenceDoc   Internal reference doc / remito number.
     * @return array{lot: StockLot, movement: StockMovement, centralStock: CentralStock}
     */
    public function execute(
        string $productId,
        int $quantityBoxes,
        string $importDate,
        string $lotNumber,
        ?string $supplier = null,
        ?string $expiryDate = null,
        ?string $referenceDoc = null,
    ): array {
        /** @var User $actor */
        $actor = Auth::user();

        if (! $actor->role->isDirector()) {
            throw new UnauthorizedException('Only Directors can register stock imports.');
        }

        return DB::transaction(function () use (
            $actor, $productId, $quantityBoxes, $importDate,
            $lotNumber, $supplier, $expiryDate, $referenceDoc,
        ): array {
            // 1. Create the stock lot
            $lot = StockLot::create([
                'product_id'     => $productId,
                'lot_number'     => $lotNumber,
                'import_date'    => $importDate,
                'supplier'       => $supplier,
                'quantity_boxes' => $quantityBoxes,
                'expiry_date'    => $expiryDate,
                'registered_by'  => $actor->id,
            ]);

            // 2. Create the import movement
            $movement = StockMovement::create([
                'movement_type'   => StockMovementType::Import,
                'product_id'      => $productId,
                'from_entity_type' => null,
                'from_entity_id'  => null,
                'to_entity_type'  => 'central',
                'to_entity_id'    => null,
                'quantity_boxes'  => $quantityBoxes,
                'quantity_units'  => 0,
                'lot_id'          => $lot->id,
                'reference_doc'   => $referenceDoc ?? $lotNumber,
                'sale_id'         => null,
                'created_by'      => $actor->id,
                'created_at'      => now(),
            ]);

            // 3. Upsert central_stock — increment total_imported
            $centralStock = CentralStock::firstOrCreate(
                ['product_id' => $productId],
                ['total_imported' => 0, 'total_dispatched' => 0, 'minimum_stock' => 0],
            );

            $centralStock->increment('total_imported', $quantityBoxes);
            $centralStock->refresh();

            return compact('lot', 'movement', 'centralStock');
        });
    }
}
