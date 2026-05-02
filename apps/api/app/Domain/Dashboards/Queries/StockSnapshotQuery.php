<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Queries;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Materialised stock snapshot combining central, distributor, and seller
 * stock in a single aggregation pass per product.
 *
 * Returns collections keyed by context:
 *   forSeller($id)      — seller_stock rows for that seller
 *   forDistributor($id) — distributor_stock + all sellers in their zone
 *   central()           — central_stock rows (Director-level)
 *
 * Uses raw DB facade per PLAN.md aggregation decision.
 * RLS set by middleware — these queries run inside the same transaction.
 */
final class StockSnapshotQuery
{
    /**
     * Stock per product for a specific seller.
     *
     * @return Collection<int, object{product_id:string, product_name:string, boxes:int, units_loose:int, reserved:int, available:int, min_stock:int, below_min:bool}>
     */
    public function forSeller(string $sellerId): Collection
    {
        return DB::table('seller_stock')
            ->join('products', 'products.id', '=', 'seller_stock.product_id')
            ->where('seller_stock.seller_id', $sellerId)
            ->select([
                'seller_stock.product_id',
                'products.name as product_name',
                'seller_stock.boxes',
                'seller_stock.loose_units',
                'seller_stock.reserved_boxes as reserved',
                DB::raw('(seller_stock.boxes - seller_stock.reserved_boxes) AS available'),
                'seller_stock.minimum_stock as min_stock',
                DB::raw('((seller_stock.boxes - seller_stock.reserved_boxes) < seller_stock.minimum_stock) AS below_min'),
            ])
            ->get();
    }

    /**
     * Own stock for a distributor + every seller's stock in their zone.
     *
     * @return array{own: Collection, sellers: Collection}
     */
    public function forDistributor(string $distributorId): array
    {
        $own = DB::table('distributor_stock')
            ->join('products', 'products.id', '=', 'distributor_stock.product_id')
            ->where('distributor_stock.distributor_id', $distributorId)
            ->select([
                'distributor_stock.product_id',
                'products.name as product_name',
                'distributor_stock.available',
                'distributor_stock.minimum_stock as min_stock',
                DB::raw('(distributor_stock.available < distributor_stock.minimum_stock) AS below_min'),
            ])
            ->get();

        // All sellers in zones managed by this distributor (via customers assignment)
        $sellersStock = DB::table('seller_stock')
            ->join('products', 'products.id', '=', 'seller_stock.product_id')
            ->join('users', 'users.id', '=', 'seller_stock.seller_id')
            ->whereIn('seller_stock.seller_id', function ($sub) use ($distributorId): void {
                $sub->select('customers.assigned_seller_id')
                    ->from('customers')
                    ->join('zones', 'zones.id', '=', 'customers.zone_id')
                    ->where('zones.distributor_id', $distributorId)
                    ->whereNotNull('customers.assigned_seller_id');
            })
            ->select([
                'seller_stock.seller_id',
                'users.full_name as seller_name',
                'seller_stock.product_id',
                'products.name as product_name',
                'seller_stock.boxes',
                'seller_stock.loose_units',
                'seller_stock.reserved_boxes as reserved',
                DB::raw('(seller_stock.boxes - seller_stock.reserved_boxes) AS available'),
                'seller_stock.minimum_stock as min_stock',
                DB::raw('((seller_stock.boxes - seller_stock.reserved_boxes) < seller_stock.minimum_stock) AS below_min'),
            ])
            ->get();

        return ['own' => $own, 'sellers' => $sellersStock];
    }

    /**
     * Central stock snapshot (Director-only view).
     *
     * @return Collection<int, object>
     */
    public function central(): Collection
    {
        return DB::table('central_stock')
            ->join('products', 'products.id', '=', 'central_stock.product_id')
            ->select([
                'central_stock.product_id',
                'products.name as product_name',
                'central_stock.available as boxes_available',
                'central_stock.minimum_stock as min_stock',
                DB::raw('(central_stock.available < central_stock.minimum_stock) AS below_min'),
            ])
            ->get();
    }

    /**
     * All sellers and distributors below their minimum stock (Director view).
     *
     * @return Collection<int, object>
     */
    public function criticalActors(): Collection
    {
        $sellers = DB::table('seller_stock')
            ->join('products', 'products.id', '=', 'seller_stock.product_id')
            ->join('users', 'users.id', '=', 'seller_stock.seller_id')
            ->whereRaw('(seller_stock.boxes - seller_stock.reserved_boxes) < seller_stock.minimum_stock')
            ->select([
                DB::raw("'seller' AS actor_type"),
                'seller_stock.seller_id as actor_id',
                'users.full_name as actor_name',
                'seller_stock.product_id',
                'products.name as product_name',
                DB::raw('(seller_stock.boxes - seller_stock.reserved_boxes) AS available'),
                'seller_stock.minimum_stock as min_stock',
            ]);

        $distributors = DB::table('distributor_stock')
            ->join('products', 'products.id', '=', 'distributor_stock.product_id')
            ->join('users', 'users.id', '=', 'distributor_stock.distributor_id')
            ->whereRaw('distributor_stock.available < distributor_stock.minimum_stock')
            ->select([
                DB::raw("'distributor' AS actor_type"),
                'distributor_stock.distributor_id as actor_id',
                'users.full_name as actor_name',
                'distributor_stock.product_id',
                'products.name as product_name',
                'distributor_stock.available',
                'distributor_stock.minimum_stock as min_stock',
            ]);

        return $sellers->union($distributors)->get();
    }
}
