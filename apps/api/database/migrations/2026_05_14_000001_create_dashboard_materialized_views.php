<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 14 — Materialized views for Director dashboard heavy queries.
 *
 * Views created WITH NO DATA so the migration runs fast.
 * Initial population + subsequent refreshes are handled by
 * RefreshDashboardMatViewsJob scheduled in routes/console.php.
 *
 * UNIQUE indexes are required for REFRESH MATERIALIZED VIEW CONCURRENTLY.
 *
 * Views:
 *   mv_director_pulse_today    — Today's global sales/cobros aggregation.
 *                                Refresh every 5 min.
 *   mv_portfolio_health_monthly — Last 12 months customer state transitions.
 *                                Refresh hourly.
 *   mv_zone_health             — Per-zone inactive % + ranking.
 *                                Refresh hourly.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * Materialized views are DDL — they are not wrapped in a transaction
     * on Postgres because CREATE MATERIALIZED VIEW cannot run inside a
     * transaction block that has already executed DDL in the same session.
     * Laravel's migration runner wraps each migration in a transaction by
     * default; we disable that here via $withinTransaction = false.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        // ------------------------------------------------------------------
        // mv_director_pulse_today
        // Aggregates today's delivered sales and non-reversed payments.
        // The pulse_date column is the materialized day so we can query by
        // date key after a refresh (avoids relying on NOW() inside the MV).
        // ------------------------------------------------------------------
        DB::statement("
            CREATE MATERIALIZED VIEW mv_director_pulse_today AS
            SELECT
                CURRENT_DATE                                                AS pulse_date,
                COUNT(DISTINCT s.id)                                        AS sales_count,
                COALESCE(SUM(si.quantity_boxes), 0)                         AS sales_boxes,
                COALESCE(SUM(s.total_amount), 0.0000)                       AS sales_amount,
                COALESCE(SUM(CASE WHEN p.amount_currency = 'ARS' AND p.reversed = false THEN p.amount_amount ELSE 0 END), 0.0000)
                                                                            AS cobros_ars,
                COALESCE(SUM(CASE WHEN p.amount_currency = 'USD' AND p.reversed = false THEN p.amount_amount ELSE 0 END), 0.0000)
                                                                            AS cobros_usd
            FROM sales s
            LEFT JOIN sale_items si ON si.sale_id = s.id
            LEFT JOIN payments p ON p.sale_id = s.id
            WHERE s.status = 'delivered'
              AND s.sale_date = CURRENT_DATE
            WITH NO DATA
        ");

        // UNIQUE index on pulse_date enables CONCURRENTLY refresh.
        DB::statement(
            'CREATE UNIQUE INDEX mv_director_pulse_today_date_idx ON mv_director_pulse_today (pulse_date)'
        );

        // ------------------------------------------------------------------
        // mv_portfolio_health_monthly
        // Customer evolution state transitions per calendar month.
        // Computed from purchase_evolution_metrics joined to customers.
        // ------------------------------------------------------------------
        DB::statement("
            CREATE MATERIALIZED VIEW mv_portfolio_health_monthly AS
            SELECT
                DATE_TRUNC('month', pem.computed_at)::date            AS period_month,
                COUNT(DISTINCT c.id)                                  AS total_customers,
                COUNT(DISTINCT CASE
                    WHEN pem.evolution_state NOT IN ('inactive') THEN c.id
                END)                                                  AS active_count,
                COUNT(DISTINCT CASE
                    WHEN pem.evolution_state = 'first_purchase' THEN c.id
                END)                                                  AS new_count,
                COUNT(DISTINCT CASE
                    WHEN pem.evolution_state = 'inactive' THEN c.id
                END)                                                  AS lost_count,
                COUNT(DISTINCT CASE
                    WHEN pem.evolution_state IN ('decreasing', 'inactive') THEN c.id
                END)                                                  AS at_risk_count
            FROM purchase_evolution_metrics pem
            JOIN customers c ON c.id = pem.customer_id
            WHERE pem.computed_at >= NOW() - INTERVAL '12 months'
            GROUP BY DATE_TRUNC('month', pem.computed_at)
            WITH NO DATA
        ");

        DB::statement(
            'CREATE UNIQUE INDEX mv_portfolio_health_monthly_month_idx ON mv_portfolio_health_monthly (period_month)'
        );

        // ------------------------------------------------------------------
        // mv_zone_health
        // Per-zone inactive percentage + Vendedor count for the Director
        // 'zone at risk' widget. Feeds ZoneRankingQuery and PortfolioHealthQuery.
        // ------------------------------------------------------------------
        DB::statement("
            CREATE MATERIALIZED VIEW mv_zone_health AS
            SELECT
                z.id                                                   AS zone_id,
                z.name                                                 AS zone_name,
                z.distributor_id,
                COUNT(DISTINCT c.id)                                   AS total_customers,
                COUNT(DISTINCT CASE
                    WHEN pem.evolution_state = 'inactive' THEN c.id
                END)                                                   AS inactive_count,
                ROUND(
                    100.0 * COUNT(DISTINCT CASE
                        WHEN pem.evolution_state = 'inactive' THEN c.id
                    END) / NULLIF(COUNT(DISTINCT c.id), 0),
                    2
                )                                                      AS inactive_pct,
                COUNT(DISTINCT s.seller_id)                            AS seller_count
            FROM zones z
            LEFT JOIN customers c ON c.zone_id = z.id AND c.is_active = true
            LEFT JOIN purchase_evolution_metrics pem ON pem.customer_id = c.id
            LEFT JOIN (
                SELECT DISTINCT seller_id, zone_id
                FROM sales
                WHERE status = 'delivered'
                  AND sale_date >= (NOW() - INTERVAL '90 days')::date
            ) s ON s.zone_id = z.id
            GROUP BY z.id, z.name, z.distributor_id
            WITH NO DATA
        ");

        DB::statement(
            'CREATE UNIQUE INDEX mv_zone_health_zone_id_idx ON mv_zone_health (zone_id)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_zone_health');
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_portfolio_health_monthly');
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_director_pulse_today');
    }
};
