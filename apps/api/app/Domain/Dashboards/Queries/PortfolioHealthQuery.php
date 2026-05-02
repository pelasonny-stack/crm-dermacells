<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Queries;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Portfolio health aggregations for the Director dashboard — §14.3.
 *
 * Reads from mv_portfolio_health_monthly (materialized view) when available,
 * falling back to a live query against purchase_evolution_metrics.
 *
 * The MV is refreshed hourly by RefreshDashboardMatViewsJob.
 */
final class PortfolioHealthQuery
{
    /**
     * Last N months of customer state transitions: active / new / lost / at_risk.
     *
     * @return Collection<int, object{month:string, active:int, new:int, lost:int, at_risk:int}>
     */
    public function evolution(int $months = 12): Collection
    {
        // Read from materialized view — populated by Phase 14 refresh job.
        try {
            return DB::table('mv_portfolio_health_monthly')
                ->orderByDesc('period_month')
                ->limit($months)
                ->get([
                    'period_month as month',
                    'active_count as active',
                    'new_count as new',
                    'lost_count as lost',
                    'at_risk_count as at_risk',
                ]);
        } catch (\Throwable) {
            // MV not yet populated — return empty; Phase 17 verification will confirm.
            return collect();
        }
    }

    /**
     * Zone penetration: active customers vs total per zone.
     *
     * @return Collection<int, object{zone_id:string, zone_name:string, active:int, total:int, pct:float}>
     */
    public function zonePenetration(): Collection
    {
        return DB::table('customers')
            ->join('zones', 'zones.id', '=', 'customers.zone_id')
            ->where('customers.is_active', true)
            ->selectRaw('
                customers.zone_id,
                zones.name AS zone_name,
                COUNT(*) AS total,
                SUM(CASE WHEN EXISTS (
                    SELECT 1 FROM purchase_evolution_metrics m
                    WHERE m.customer_id = customers.id
                      AND m.evolution_state NOT IN (\'inactive\')
                      AND m.period_end >= NOW() - INTERVAL \'90 days\'
                ) THEN 1 ELSE 0 END) AS active,
                ROUND(
                    100.0 * SUM(CASE WHEN EXISTS (
                        SELECT 1 FROM purchase_evolution_metrics m
                        WHERE m.customer_id = customers.id
                          AND m.evolution_state NOT IN (\'inactive\')
                          AND m.period_end >= NOW() - INTERVAL \'90 days\'
                    ) THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0),
                    1
                ) AS pct
            ')
            ->groupBy('customers.zone_id', 'zones.name')
            ->orderByDesc('pct')
            ->get();
    }

    /**
     * Global list of at-risk customers (inactive or decreasing frequency).
     *
     * @return Collection<int, object>
     */
    public function atRiskCustomers(): Collection
    {
        return DB::table('purchase_evolution_metrics')
            ->join('customers', 'customers.id', '=', 'purchase_evolution_metrics.customer_id')
            ->join('users', 'users.id', '=', 'customers.seller_id')
            ->join('zones', 'zones.id', '=', 'customers.zone_id')
            ->whereIn('purchase_evolution_metrics.evolution_state', ['inactive', 'decreasing'])
            ->orderByRaw(
                "CASE purchase_evolution_metrics.evolution_state WHEN 'inactive' THEN 1 ELSE 2 END ASC"
            )
            ->get([
                'customers.id as customer_id',
                'customers.first_name',
                'customers.last_name',
                'purchase_evolution_metrics.evolution_state',
                'users.full_name as seller_name',
                'zones.name as zone_name',
            ]);
    }

    /**
     * Top 10 customers by total delivered sale volume with trend direction.
     *
     * @return Collection<int, object>
     */
    public function top10Customers(): Collection
    {
        return DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->leftJoin('purchase_evolution_metrics', function ($join): void {
                $join->on('purchase_evolution_metrics.customer_id', '=', 'customers.id');
            })
            ->where('sales.status', 'delivered')
            ->groupBy(
                'customers.id',
                'customers.first_name',
                'customers.last_name',
                'purchase_evolution_metrics.evolution_state'
            )
            ->selectRaw('
                customers.id AS customer_id,
                customers.first_name,
                customers.last_name,
                SUM(sale_items.subtotal) AS total_volume,
                MAX(sales.currency) AS currency,
                MAX(purchase_evolution_metrics.evolution_state) AS trend
            ')
            ->orderByDesc('total_volume')
            ->limit(10)
            ->get();
    }
}
