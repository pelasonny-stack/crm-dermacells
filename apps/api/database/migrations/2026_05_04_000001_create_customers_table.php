<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the customers table — the central entity of §3.
 *
 * KEY DESIGN DECISIONS
 * ====================
 * - UUID primary key (gen_random_uuid()) consistent with Phase 1 conventions.
 * - reference_price_* stored as NUMERIC(18,4) + CHAR(3) compound columns so
 *   MoneyCast can hydrate them into an immutable Brick\Money\Money value object.
 * - zone_id is nullable to accommodate customer records migrated from pre-zone
 *   data imports. In practice all new customers must have a zone (enforced at
 *   the application layer via Form Request validation).
 * - deactivated_by / deactivation_reason support the Director-forced deactivation
 *   flow (§3.10). Both are NULL while the customer is active.
 * - The table does NOT use a deleted_at soft-delete column — customers are
 *   deactivated (is_active = false) rather than deleted. This preserves
 *   referential integrity for historical sales, invoices and payments.
 *
 * INDEXES
 * =======
 * - idx_customers_assigned_seller_id — primary hot path: Seller fetching their list.
 * - idx_customers_zone_id — Distributor zone scope queries.
 * - idx_customers_category_id — Director category-D access checks.
 * - idx_customers_cuit — uniqueness + lookup by CUIT (§3.9).
 * - idx_customers_is_active — filter active customers efficiently.
 * - idx_customers_first_purchase_date — evolution engine nightly aggregations (§10).
 *
 * RLS policies are created in migration 2026_05_04_000005_enable_customers_rls.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE customers (
                id                          UUID            PRIMARY KEY DEFAULT gen_random_uuid(),

                -- Identity
                first_name                  TEXT            NOT NULL,
                last_name                   TEXT            NOT NULL,
                cuit                        CHAR(11)        NOT NULL,
                phone                       TEXT            NOT NULL,
                email                       TEXT            NOT NULL,
                address                     TEXT            NOT NULL,

                -- Classification & assignment
                category_id                 UUID            NOT NULL
                                            REFERENCES customer_categories (id),
                zone_id                     UUID            NULL
                                            REFERENCES zones (id) ON DELETE SET NULL,
                assigned_seller_id          UUID            NOT NULL
                                            REFERENCES users (id),
                default_payment_terms_id    UUID            NOT NULL
                                            REFERENCES payment_terms (id),

                -- Reference price (compound Money: amount + currency)
                reference_price_amount      NUMERIC(18, 4)  NULL,
                reference_price_currency    CHAR(3)         NULL DEFAULT 'USD',
                -- Unit-level price (caja / 5, independently editable)
                reference_price_unit_amount NUMERIC(18, 4)  NULL,

                -- Purchase evolution engine (§10)
                purchase_frequency_days     INTEGER         NULL,
                first_purchase_date         DATE            NULL,

                -- Lifecycle
                is_active                   BOOLEAN         NOT NULL DEFAULT TRUE,
                deactivated_at              TIMESTAMPTZ     NULL,
                deactivated_by              UUID            NULL
                                            REFERENCES users (id),
                deactivation_reason         TEXT            NULL,

                created_at                  TIMESTAMPTZ     NULL,
                updated_at                  TIMESTAMPTZ     NULL,

                CONSTRAINT uq_customers_cuit UNIQUE (cuit),

                -- reference_price currency must be provided when amount is set
                CONSTRAINT chk_customers_ref_price_currency
                    CHECK (
                        (reference_price_amount IS NULL AND reference_price_currency IS NULL)
                        OR (reference_price_amount IS NOT NULL AND reference_price_currency IS NOT NULL)
                    )
            )
        SQL);

        // Hot path: Seller queries their assigned customer list
        DB::statement('CREATE INDEX idx_customers_assigned_seller_id ON customers (assigned_seller_id)');

        // Distributor zone scope
        DB::statement('CREATE INDEX idx_customers_zone_id ON customers (zone_id)');

        // Category-D access checks and category filtering
        DB::statement('CREATE INDEX idx_customers_category_id ON customers (category_id)');

        // CUIT lookup (uniqueness constraint already implies an index, but
        // explicit index name aids query plan debugging)
        DB::statement('CREATE INDEX idx_customers_cuit ON customers (cuit)');

        // Active customer list filtering
        DB::statement('CREATE INDEX idx_customers_is_active ON customers (is_active)');

        // Evolution engine nightly aggregations (§10)
        DB::statement('CREATE INDEX idx_customers_first_purchase_date ON customers (first_purchase_date) WHERE first_purchase_date IS NOT NULL');

        // Composite: common query pattern for a Seller's active customers in a zone
        DB::statement('CREATE INDEX idx_customers_seller_zone_active ON customers (assigned_seller_id, zone_id, is_active)');

        // Role grants
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON customers TO app_role');
        DB::statement('GRANT SELECT ON customers TO report_role');
        DB::statement('GRANT SELECT ON customers TO worker_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS customers CASCADE');
    }
};
