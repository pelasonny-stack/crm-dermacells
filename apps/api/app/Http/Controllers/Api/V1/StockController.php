<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Stock\DispatchDirectToSellerAction;
use App\Actions\Stock\DispatchToDistributorAction;
use App\Actions\Stock\RedistributeFromDistributorAction;
use App\Actions\Stock\RegisterImportAction;
use App\Enums\UserRole;
use App\Exceptions\StockInsufficientException;
use App\Http\Controllers\Controller;
use App\Models\CentralStock;
use App\Models\DistributorStock;
use App\Models\SellerStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Stock management endpoints — Phase 4.
 *
 * All routes require auth:sanctum middleware. Role-based access is enforced
 * at the action layer (throwing UnauthorizedException) and in policy guards
 * inline in controller methods.
 *
 * RFC 7807 problem+json responses are used for errors (matching Phase 0 spec).
 */
class StockController extends Controller
{
    // =========================================================================
    // Central stock
    // =========================================================================

    /**
     * GET /v1/stock/central
     *
     * Director only. Returns current central inventory per product.
     */
    public function centralIndex(): JsonResponse
    {
        $this->requireRole(UserRole::Director);

        $rows = CentralStock::with('product')
            ->orderBy('created_at')
            ->get()
            ->map(fn (CentralStock $cs) => [
                'id'               => $cs->id,
                'product_id'       => $cs->product_id,
                'product_name'     => $cs->product->name ?? null,
                'total_imported'   => $cs->total_imported,
                'total_dispatched' => $cs->total_dispatched,
                'available'        => $cs->available,
                'minimum_stock'    => $cs->minimum_stock,
                'below_minimum'    => $cs->available < $cs->minimum_stock,
                'updated_at'       => $cs->updated_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $rows]);
    }

    /**
     * POST /v1/stock/central/imports
     *
     * Director only. Registers a new importation.
     */
    public function registerImport(Request $request, RegisterImportAction $action): JsonResponse
    {
        $this->requireRole(UserRole::Director);

        $validated = $request->validate([
            'product_id'     => ['required', 'uuid', 'exists:products,id'],
            'quantity_boxes' => ['required', 'integer', 'min:1'],
            'import_date'    => ['required', 'date', 'before_or_equal:today'],
            'lot_number'     => ['required', 'string', 'max:255'],
            'supplier'       => ['nullable', 'string', 'max:255'],
            'expiry_date'    => ['nullable', 'date', 'after:import_date'],
            'reference_doc'  => ['nullable', 'string', 'max:255'],
        ]);

        $result = $action->execute(
            productId: $validated['product_id'],
            quantityBoxes: (int) $validated['quantity_boxes'],
            importDate: $validated['import_date'],
            lotNumber: $validated['lot_number'],
            supplier: $validated['supplier'] ?? null,
            expiryDate: $validated['expiry_date'] ?? null,
            referenceDoc: $validated['reference_doc'] ?? null,
        );

        return response()->json([
            'data' => [
                'lot_id'         => $result['lot']->id,
                'movement_id'    => $result['movement']->id,
                'available'      => $result['centralStock']->available,
                'total_imported' => $result['centralStock']->total_imported,
            ],
        ], 201);
    }

    /**
     * POST /v1/stock/central/dispatches
     *
     * Director only. Dispatches to distributor or directly to seller.
     */
    public function dispatchFromCentral(
        Request $request,
        DispatchToDistributorAction $toDistributor,
        DispatchDirectToSellerAction $toSeller,
    ): JsonResponse {
        $this->requireRole(UserRole::Director);

        $validated = $request->validate([
            'product_id'       => ['required', 'uuid', 'exists:products,id'],
            'destination_type' => ['required', Rule::in(['distributor', 'seller'])],
            'destination_id'   => ['required', 'uuid', 'exists:users,id'],
            'quantity_boxes'   => ['required', 'integer', 'min:1'],
            'reference_doc'    => ['nullable', 'string', 'max:255'],
        ]);

        try {
            if ($validated['destination_type'] === 'distributor') {
                $result = $toDistributor->execute(
                    productId: $validated['product_id'],
                    distributorId: $validated['destination_id'],
                    quantityBoxes: (int) $validated['quantity_boxes'],
                    referenceDoc: $validated['reference_doc'] ?? null,
                );
            } else {
                $result = $toSeller->execute(
                    productId: $validated['product_id'],
                    sellerId: $validated['destination_id'],
                    quantityBoxes: (int) $validated['quantity_boxes'],
                    referenceDoc: $validated['reference_doc'] ?? null,
                );
            }
        } catch (StockInsufficientException $e) {
            return $this->insufficientStockResponse($e);
        }

        return response()->json(['data' => ['movement_id' => $result['movement']->id]], 201);
    }

    // =========================================================================
    // Distributor stock
    // =========================================================================

    /**
     * GET /v1/stock/distributor
     *
     * Director sees all. Distributor sees own.
     */
    public function distributorIndex(): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if ($user->role === UserRole::Seller) {
            return $this->forbiddenResponse('Sellers cannot view distributor stock.');
        }

        $query = DistributorStock::with(['distributor', 'product'])
            ->orderBy('distributor_id')
            ->orderBy('product_id');

        // Distributor-scoped: RLS already filters, but we add explicit where for clarity
        if ($user->role === UserRole::Distributor) {
            $query->where('distributor_id', $user->id);
        }

        $rows = $query->get()->map(fn (DistributorStock $ds) => [
            'id'                   => $ds->id,
            'distributor_id'       => $ds->distributor_id,
            'distributor_name'     => $ds->distributor->full_name ?? null,
            'product_id'           => $ds->product_id,
            'product_name'         => $ds->product->name ?? null,
            'total_received'       => $ds->total_received,
            'total_redistributed'  => $ds->total_redistributed,
            'reserved'             => $ds->reserved,
            'available'            => $ds->available,
            'minimum_stock'        => $ds->minimum_stock,
            'below_minimum'        => $ds->available < $ds->minimum_stock && $ds->minimum_stock > 0,
            'updated_at'           => $ds->updated_at?->toIso8601String(),
        ]);

        return response()->json(['data' => $rows]);
    }

    // =========================================================================
    // Seller stock
    // =========================================================================

    /**
     * GET /v1/stock/seller
     *
     * Seller sees own. Distributor sees zone sellers (via RLS).
     * Director sees all.
     */
    public function sellerIndex(): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $query = SellerStock::with(['seller', 'product'])
            ->orderBy('seller_id')
            ->orderBy('product_id');

        // Seller-scoped
        if ($user->role === UserRole::Seller) {
            $query->where('seller_id', $user->id);
        }

        $rows = $query->get()->map(fn (SellerStock $ss) => [
            'id'              => $ss->id,
            'seller_id'       => $ss->seller_id,
            'seller_name'     => $ss->seller->full_name ?? null,
            'product_id'      => $ss->product_id,
            'product_name'    => $ss->product->name ?? null,
            'boxes'           => $ss->boxes,
            'loose_units'     => $ss->loose_units,
            'reserved_boxes'  => $ss->reserved_boxes,
            'reserved_units'  => $ss->reserved_units,
            'available_boxes' => $ss->availableBoxes(),
            'available_units' => $ss->availableUnits(),
            'minimum_stock'   => $ss->minimum_stock,
            'below_minimum'   => $ss->availableBoxes() < $ss->minimum_stock && $ss->minimum_stock > 0,
            'updated_at'      => $ss->updated_at?->toIso8601String(),
        ]);

        return response()->json(['data' => $rows]);
    }

    // =========================================================================
    // Redistribution
    // =========================================================================

    /**
     * POST /v1/stock/redistribute
     *
     * Distributor only. Sends boxes to a seller in their zone.
     */
    public function redistribute(Request $request, RedistributeFromDistributorAction $action): JsonResponse
    {
        $this->requireRole(UserRole::Distributor);

        $validated = $request->validate([
            'product_id'     => ['required', 'uuid', 'exists:products,id'],
            // NOTE: seller_id FK existence is enforced by the DB constraint on
            // seller_stock.seller_id → users.id. We intentionally omit the
            // `exists:users,id` Eloquent rule here because the distributor GUC
            // restricts the users-table visibility to self-only via RLS, which
            // would cause the validation to fail for any valid seller UUID.
            'seller_id'      => ['required', 'uuid'],
            'quantity_boxes' => ['required', 'integer', 'min:1'],
            'reference_doc'  => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $action->execute(
                productId: $validated['product_id'],
                sellerId: $validated['seller_id'],
                quantityBoxes: (int) $validated['quantity_boxes'],
                referenceDoc: $validated['reference_doc'] ?? null,
            );
        } catch (StockInsufficientException $e) {
            return $this->insufficientStockResponse($e);
        }

        return response()->json(['data' => ['movement_id' => $result['movement']->id]], 201);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function requireRole(UserRole $role): void
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if ($user->role !== $role) {
            abort(403, "Access restricted to {$role->value} role.");
        }
    }

    private function forbiddenResponse(string $detail): JsonResponse
    {
        return response()->json([
            'type'   => 'https://crm.dermacells.ar/errors/forbidden',
            'title'  => 'Forbidden',
            'status' => 403,
            'code'   => 'FORBIDDEN',
            'detail' => $detail,
        ], 403, ['Content-Type' => 'application/problem+json']);
    }

    private function insufficientStockResponse(StockInsufficientException $e): JsonResponse
    {
        return response()->json([
            'type'      => 'https://crm.dermacells.ar/errors/stock-insufficient',
            'title'     => 'Stock Insufficient',
            'status'    => 422,
            'code'      => 'STOCK_INSUFFICIENT',
            'detail'    => $e->getMessage(),
            'meta'      => [
                'product_id' => $e->productId,
                'requested'  => $e->requested,
                'available'  => $e->available,
                'source'     => $e->sourceType,
            ],
        ], 422, ['Content-Type' => 'application/problem+json']);
    }
}
