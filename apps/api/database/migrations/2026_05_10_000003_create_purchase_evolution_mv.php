<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 10 — mv_customer_product_purchases materialized view.
 *
 * Aggregates delivered sale_items grouped by (customer_id, product_id)
 * from sales JOIN sale_items WHERE sales.status = 'delivered'.
 *
 * Columns:
 *   customer_id      — UUID
 *   product_id       — UUID
 *   total_purchases  — count of delivered sale rows for this pair
 *   last_date        — date of the most recent delivered sale
 *   avg_interval     — average days between consecutive purchases (NULL when <2)
 *   intervals_array  — ARRAY of integer intervals in chronological order
 *
 * The UNIQUE INDEX on (customer_id, product_id) is mandatory for
 * REFRESH CONCURRENTLY (Postgres requirement: at least one unique index).
 *
 * Refresh is performed nightly by EvolutionEngine::recompute() using
 * REFRESH MATERIALIZED VIEW CONCURRENTLY which does not block reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW IF NOT EXISTS mv_customer_product_purchases AS
            SELECT
                s.customer_id                                                   AS customer_id,
                si.product_id                                                   AS product_id,
                COUNT(DISTINCT s.id)                                            AS total_purchases,
                MAX(s.sale_date)                                                AS last_date,
                CASE
                    WHEN COUNT(DISTINCT s.sale_date) < 2 THEN NULL
                    ELSE ROUND(
                        AVG(gap) :: NUMERIC,
                        2
                    )
                END                                                             AS avg_interval,
                ARRAY_AGG(gap ORDER BY ord)
                    FILTER (WHERE gap IS NOT NULL)                              AS intervals_array
            FROM (
                SELECT
                    s2.id,
                    s2.customer_id,
                    s2.sale_date,
                    si2.product_id,
                    s2.sale_date - LAG(s2.sale_date) OVER (
                        PARTITION BY s2.customer_id, si2.product_id
                        ORDER BY s2.sale_date
                    )                                                           AS gap,
                    ROW_NUMBER() OVER (
                        PARTITION BY s2.customer_id, si2.product_id
                        ORDER BY s2.sale_date
                    )                                                           AS ord
                FROM sales s2
                JOIN sale_items si2 ON si2.sale_id = s2.id
                WHERE s2.status = 'delivered'
            ) sub
            JOIN sales s  ON s.id  = sub.id
            JOIN sale_items si ON si.sale_id = s.id AND si.product_id = sub.product_id
            GROUP BY s.customer_id, si.product_id
        SQL);

        // Required for REFRESH CONCURRENTLY
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS idx_mv_cpp_customer_product
                ON mv_customer_product_purchases (customer_id, product_id)
        SQL);

        // Covering index for the EvolutionEngine UPSERT scan
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_mv_cpp_last_date
                ON mv_customer_product_purchases (last_date)
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_customer_product_purchases CASCADE');
    }
};
