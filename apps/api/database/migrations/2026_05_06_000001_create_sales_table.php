<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sales table — §5 core entity.
 *
 * DESIGN DECISIONS
 * ================
 * - UUID PK consistent with Phase 1 conventions.
 * - status stored as a Postgres ENUM so invalid states are impossible at the DB layer.
 * - currency CHAR(3) with CHECK restricts to ARS|USD; never store converted amounts.
 * - exchange_rate_id is NULL when currency='USD' (no conversion needed).
 * - total_amount + total_currency: final computed total, persisted for reporting
 *   without requiring re-join to sale_items on every read.
 * - delegated_delivery: when TRUE the Distributor of the sale's zone handles
 *   physical delivery and stock is debited from that Distributor (§5.7).
 * - delegated_distributor_id: denormalised FK to the zone's Distributor at
 *   the time of sale creation; saves a join on the hot query path.
 * - cancelled_by / cancelled_at / cancellation_reason: Director-auditable trail
 *   per §5.6. Vendedor/Distribuidor can cancel without these fields populated
 *   only if no payments exist.
 *
 * INDEXES (per Phase 0 spec)
 * ==========================
 * - (seller_id, status)        — Seller dashboard: my open sales.
 * - (zone_id, status)          — Distributor dashboard: zone open sales.
 * - (customer_id)              — Customer detail: all their sales.
 * - (status, sale_date DESC)   — Director dashboard: global listing by recency.
 * - (due_date) WHERE status NOT IN ('delivered','cancelled') — cobros vencidos scan.
 * - (delegated_delivery, status) — Phase 4 stock debit routing.
 *
 * RLS is applied in migration 2026_05_06_000006_enable_sales_rls.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ----------------------------------------------------------------
        // 1. sale_status ENUM
        // ----------------------------------------------------------------
        DB::statement(<<<'SQL'
            DO $$ BEGIN
                CREATE TYPE sale_status AS ENUM ('draft','confirmed','delivered','cancelled');
            EXCEPTION
                WHEN duplicate_object THEN NULL;
            END $$
        SQL);

        // ----------------------------------------------------------------
        // 2. sales table
        // ----------------------------------------------------------------
        DB::statement(<<<'SQL'
            CREATE TABLE sales (
                id                          UUID            PRIMARY KEY DEFAULT gen_random_uuid(),

                -- Core relationships
                customer_id                 UUID            NOT NULL
                                            REFERENCES customers (id) ON DELETE RESTRICT,
                seller_id                   UUID            NOT NULL
                                            REFERENCES users (id) ON DELETE RESTRICT,
                zone_id                     UUID            NOT NULL
                                            REFERENCES zones (id) ON DELETE RESTRICT,

                -- State machine (§5.5)
                status                      sale_status     NOT NULL DEFAULT 'draft',

                -- Dates
                sale_date                   DATE            NOT NULL,

                -- Payment terms (§5.4)
                payment_terms_id            UUID            NOT NULL
                                            REFERENCES payment_terms (id) ON DELETE RESTRICT,
                due_date                    DATE            NULL,

                -- Money (§5.3): dual-currency, never stored as converted amount
                currency                    CHAR(3)         NOT NULL,
                exchange_rate_id            UUID            NULL
                                            REFERENCES exchange_rates (id) ON DELETE RESTRICT,
                total_amount                NUMERIC(18,4)   NOT NULL DEFAULT 0,
                total_currency              CHAR(3)         NOT NULL,

                -- Delegated delivery (§5.7)
                delegated_delivery          BOOLEAN         NOT NULL DEFAULT FALSE,
                delegated_distributor_id    UUID            NULL
                                            REFERENCES users (id) ON DELETE SET NULL,

                -- Cancellation audit (§5.6)
                cancellation_reason         TEXT            NULL,
                cancelled_by               UUID            NULL
                                            REFERENCES users (id) ON DELETE SET NULL,
                cancelled_at               TIMESTAMPTZ     NULL,

                created_at                  TIMESTAMPTZ     NULL,
                updated_at                  TIMESTAMPTZ     NULL,

                -- Currency must be ARS or USD (§5.3)
                CONSTRAINT chk_sales_currency
                    CHECK (currency IN ('ARS', 'USD')),

                CONSTRAINT chk_sales_total_currency
                    CHECK (total_currency IN ('ARS', 'USD')),

                -- USD sales do not need an exchange rate; ARS sales require one
                CONSTRAINT chk_sales_exchange_rate_required_for_ars
                    CHECK (
                        currency = 'USD'
                        OR (currency = 'ARS' AND exchange_rate_id IS NOT NULL)
                    ),

                -- Cancellation metadata must be consistent
                CONSTRAINT chk_sales_cancellation_consistency
                    CHECK (
                        (status = 'cancelled' AND cancelled_at IS NOT NULL)
                        OR status != 'cancelled'
                    ),

                -- Total must be non-negative
                CONSTRAINT chk_sales_total_non_negative
                    CHECK (total_amount >= 0),

                -- Delegated delivery requires a delegated distributor
                CONSTRAINT chk_sales_delegated_delivery_distributor
                    CHECK (
                        (delegated_delivery = FALSE)
                        OR (delegated_delivery = TRUE AND delegated_distributor_id IS NOT NULL)
                    )
            )
        SQL);

        // ----------------------------------------------------------------
        // 3. Indexes
        // ----------------------------------------------------------------
        DB::statement('CREATE INDEX idx_sales_seller_status ON sales (seller_id, status)');
        DB::statement('CREATE INDEX idx_sales_zone_status ON sales (zone_id, status)');
        DB::statement('CREATE INDEX idx_sales_customer_id ON sales (customer_id)');
        DB::statement('CREATE INDEX idx_sales_status_date ON sales (status, sale_date DESC)');
        DB::statement(
            "CREATE INDEX idx_sales_due_date_open ON sales (due_date) "
            . "WHERE status NOT IN ('delivered','cancelled')"
        );
        DB::statement('CREATE INDEX idx_sales_delegated ON sales (delegated_delivery, status)');
        DB::statement('CREATE INDEX idx_sales_payment_terms ON sales (payment_terms_id)');

        // ----------------------------------------------------------------
        // 4. Role grants
        // ----------------------------------------------------------------
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON sales TO app_role');
        DB::statement('GRANT SELECT ON sales TO report_role');
        DB::statement('GRANT SELECT ON sales TO worker_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS sales CASCADE');
        DB::statement('DROP TYPE IF EXISTS sale_status CASCADE');
    }
};
