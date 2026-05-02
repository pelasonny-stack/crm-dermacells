<?php

declare(strict_types=1);

namespace App\Data\Dashboards;

use Spatie\LaravelData\Data;

/**
 * DTO for the Vendedor dashboard — §14.1.
 *
 * Sections:
 *   today       — Alerts, birthdays, cobros due within 48h, AI recommendation.
 *   my_month    — Cobrado USD/ARS, commission accumulated, sales vs prior month,
 *                 pending collections semaphore, goal progress.
 *   my_operation — Stock per product, confirmed-pending delivery, pending authorizations.
 *
 * All monetary amounts are strings (decimal representation) to avoid float
 * precision loss during JSON serialisation. Brick\Money is never serialised
 * directly — callers convert via ->getAmount()->__toString().
 */
final class SellerDashboardData extends Data
{
    public function __construct(
        /** @var array<int,array<string,mixed>> */
        public readonly array $today_alerts,

        /** @var array<int,array<string,mixed>> */
        public readonly array $today_birthdays,

        /** @var array<int,array<string,mixed>> */
        public readonly array $due_payments_48h,

        public readonly ?string $ai_recommendation,

        // --- my_month ---
        public readonly string $month_collected_usd,
        public readonly string $month_collected_ars,
        public readonly string $commission_accumulated_usd,
        public readonly string $commission_accumulated_ars,
        public readonly string $commission_tier_rate,
        public readonly int    $sales_month_count,
        public readonly string $sales_month_amount,
        public readonly int    $sales_prior_month_count,
        public readonly string $sales_prior_month_amount,

        /**
         * Pending collections semaphore:
         *   al_dia / proximo_a_vencer / vencido counts.
         *
         * @var array{al_dia:int, proximo_a_vencer:int, vencido:int}
         */
        public readonly array $pending_collections_semaphore,

        public readonly int    $goal_boxes_target,
        public readonly int    $goal_boxes_achieved,

        // --- my_operation ---
        /** @var array<int,array<string,mixed>> */
        public readonly array $stock_per_product,

        public readonly int    $confirmed_pending_delivery_count,

        /** @var array<int,array<string,mixed>> */
        public readonly array $my_pending_authorizations,
    ) {}
}
