<?php

declare(strict_types=1);

namespace App\Data\Dashboards;

use Spatie\LaravelData\Data;

/**
 * DTO for the Distribuidor dashboard — §14.2.
 *
 * Sections:
 *   zone_today        — Top 5 urgent clients in zone, sellers below stock min,
 *                       overdue cobros in zone.
 *   my_month          — Zone sales, seller ranking, cobros by modality,
 *                       6-month evolution, cobranzas evolution.
 *   my_dermacells_account — Real-time saldo a rendir, margin, commissions,
 *                            last 5 settlements, 6-month balance evolution.
 *   my_stock          — Own stock + each seller's stock in zone + alerts.
 */
final class DistributorDashboardData extends Data
{
    public function __construct(
        // --- zone_today ---
        /** @var array<int,array<string,mixed>> top 5 urgency customers */
        public readonly array $zone_urgent_customers,

        /** @var array<int,array<string,mixed>> sellers below stock minimum */
        public readonly array $zone_sellers_low_stock,

        /** @var array<int,array<string,mixed>> overdue cobros in zone */
        public readonly array $zone_overdue_payments,

        // --- my_month ---
        public readonly int    $zone_sales_month_boxes,
        public readonly string $zone_sales_month_amount,

        /** @var array<int,array<string,mixed>> ranking by collected amount */
        public readonly array $seller_ranking,

        /** @var array<int,array<string,mixed>> cobros by modality (transfer_dermacells, transfer_distributor, cash, card, cheque) */
        public readonly array $cobros_by_modality,

        /** @var array<int,array<string,mixed>> last 6 months zone sales [{month,boxes,amount}] */
        public readonly array $zone_sales_evolution_6m,

        /** @var array<int,array<string,mixed>> last 6 months cobranzas [{month,ars,usd}] */
        public readonly array $zone_cobros_evolution_6m,

        // --- my_dermacells_account ---
        public readonly string $saldo_a_rendir_ars,
        public readonly string $saldo_a_rendir_usd,
        public readonly string $margin_bruto_mes_ars,
        public readonly string $margin_bruto_mes_usd,
        public readonly string $commissions_to_pay_ars,
        public readonly string $commissions_to_pay_usd,

        /** @var array<int,array<string,mixed>> last 5 settlements */
        public readonly array $last_settlements,

        /** @var array<int,array<string,mixed>> last 6 months balance evolution [{month,saldo_ars,saldo_usd}] */
        public readonly array $balance_evolution_6m,

        // --- my_stock ---
        /** @var array<int,array<string,mixed>> own stock per product */
        public readonly array $own_stock_per_product,

        /** @var array<int,array<string,mixed>> each seller's stock per product in zone */
        public readonly array $sellers_stock_in_zone,

        /** @var array<int,array<string,mixed>> stock alerts in zone */
        public readonly array $zone_stock_alerts,
    ) {}
}
