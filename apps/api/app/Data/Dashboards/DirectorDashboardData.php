<?php

declare(strict_types=1);

namespace App\Data\Dashboards;

use Spatie\LaravelData\Data;

/**
 * DTO for the Director Ejecutivo dashboard — §14.3.
 *
 * Sections:
 *   pulse_today      — Day's ventas/cobros, pending authorizations, critical alerts.
 *   current_month    — Global vs prior month, seller/distributor rankings, goal semaphore.
 *   portfolio_health — 12-month active/new/lost, zone penetration, at-risk customers,
 *                      top-10 by volume.
 *   stock_operation  — Central stock, critical sellers/distributors, confirmed pending,
 *                      stale drafts.
 *   financial        — Settlement status per zone, global overdue, 6-month cobros
 *                      evolution, top overdue customers.
 *   system           — AI usage, integration status, last 10 authorizations.
 */
final class DirectorDashboardData extends Data
{
    public function __construct(
        // --- pulse_today (from mv_director_pulse_today) ---
        public readonly int    $today_sales_count,
        public readonly int    $today_sales_boxes,
        public readonly string $today_sales_amount,
        public readonly string $today_cobros_ars,
        public readonly string $today_cobros_usd,
        public readonly int    $pending_authorizations_count,

        /** @var array<int,array<string,mixed>> critical alerts today */
        public readonly array  $critical_alerts,

        // --- current_month ---
        public readonly string $month_sales_amount,
        public readonly string $prior_month_sales_amount,
        public readonly int    $month_sales_boxes,
        public readonly int    $prior_month_sales_boxes,

        /** @var array<int,array<string,mixed>> global seller ranking by USD-equivalent collected */
        public readonly array  $seller_ranking_global,

        /** @var array<int,array<string,mixed>> distributor ranking by zone volume */
        public readonly array  $distributor_ranking,

        /** @var array<int,array<string,mixed>> goal semaphore per seller */
        public readonly array  $goal_semaphore,

        // --- portfolio_health (from mv_portfolio_health_monthly) ---
        /** @var array<int,array<string,mixed>> last 12 months [{month,active,new,lost,at_risk}] */
        public readonly array  $portfolio_evolution_12m,

        /** @var array<int,array<string,mixed>> zone penetration [{zone,active,total,pct}] */
        public readonly array  $zone_penetration,

        /** @var array<int,array<string,mixed>> at-risk customers globally */
        public readonly array  $at_risk_customers,

        /** @var array<int,array<string,mixed>> top 10 customers by volume with trend */
        public readonly array  $top10_customers,

        // --- stock_operation ---
        /** @var array<int,array<string,mixed>> central stock per product */
        public readonly array  $central_stock,

        /** @var array<int,array<string,mixed>> sellers/distributors below minimum */
        public readonly array  $critical_stock_actors,

        public readonly int    $confirmed_pending_delivery_global,
        public readonly int    $stale_drafts_count,

        // --- financial ---
        /** @var array<int,array<string,mixed>> settlement status per distributor zone */
        public readonly array  $settlement_status_per_zone,

        public readonly string $global_overdue_amount,
        public readonly int    $global_overdue_customers_count,

        /** @var array<int,array<string,mixed>> last 6 months global cobros [{month,ars,usd}] */
        public readonly array  $cobros_evolution_6m,

        /** @var array<int,array<string,mixed>> top customers by overdue balance */
        public readonly array  $top_overdue_customers,

        // --- system ---
        public readonly bool   $ai_active,
        public readonly int    $ai_tokens_month,
        public readonly string $ai_cost_month_usd,

        /** @var array<string,array<string,mixed>> integration statuses (xubio, bcra, whatsapp, fcm) */
        public readonly array  $integration_status,

        /** @var array<int,array<string,mixed>> last 10 authorization requests */
        public readonly array  $recent_authorizations,
    ) {}
}
