<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sale items table — line items within a sale (§5.1, §5.9).
 *
 * DESIGN DECISIONS
 * ================
 * - One row per (sale, product) pair.
 * - quantity_boxes + quantity_units: Seller enters boxes and loose units separately.
 *   5 loose units = 1 box, but they are tracked independently per §8.2 stock model.
 * - unit_price_amount / unit_price_currency: the price agreed at line level,
 *   which may differ from the product's base_price or customer's reference_price
 *   when an authorization has been approved (§5.2).
 * - subtotal_amount: pre-computed and stored to avoid floating-point drift across
 *   currency conversions at read time. Application layer keeps it consistent.
 * - exchange_rate_id: NULL when unit_price_currency='USD'; set when 'ARS' so the
 *   rate that was in effect at item creation is auditable independently of the
 *   sale header rate (e.g. partially invoiced sales spanning multiple days).
 *
 * CASCADE DELETE: deleting a sale removes all its items atomically (intended —
 * sales are never hard-deleted in production, but the CASCADE makes test teardown
 * clean and protects referential integrity during rollbacks).
 *
 * RLS is inherited from the sales table via the sale_id FK: because RLS on sales
 * restricts which sale rows are visible per role, a JOIN to sale_items already
 * filters to only accessible rows. For defense-in-depth, sale_items also gets its
 * own RLS policy in migration 2026_05_06_000006.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE sale_items (
                id                      UUID            PRIMARY KEY DEFAULT gen_random_uuid(),

                sale_id                 UUID            NOT NULL
                                        REFERENCES sales (id) ON DELETE CASCADE,
                product_id              UUID            NOT NULL
                                        REFERENCES products (id) ON DELETE RESTRICT,

                -- Quantities (§8.2 seller stock model)
                quantity_boxes          INTEGER         NOT NULL DEFAULT 0,
                quantity_units          INTEGER         NOT NULL DEFAULT 0,

                -- Pricing (§5.2)
                unit_price_amount       NUMERIC(18,4)   NOT NULL,
                unit_price_currency     CHAR(3)         NOT NULL,

                -- Subtotal (boxes * unit_price + units * unit_price)
                subtotal_amount         NUMERIC(18,4)   NOT NULL,

                -- TC locked at item level for mixed-currency partial invoicing
                exchange_rate_id        UUID            NULL
                                        REFERENCES exchange_rates (id) ON DELETE RESTRICT,

                created_at              TIMESTAMPTZ     NULL,
                updated_at              TIMESTAMPTZ     NULL,

                CONSTRAINT chk_sale_items_unit_price_currency
                    CHECK (unit_price_currency IN ('ARS', 'USD')),

                CONSTRAINT chk_sale_items_quantity_non_negative
                    CHECK (quantity_boxes >= 0 AND quantity_units >= 0),

                CONSTRAINT chk_sale_items_quantity_not_both_zero
                    CHECK (quantity_boxes > 0 OR quantity_units > 0),

                CONSTRAINT chk_sale_items_units_max_four
                    CHECK (quantity_units BETWEEN 0 AND 4),

                CONSTRAINT chk_sale_items_unit_price_positive
                    CHECK (unit_price_amount > 0),

                CONSTRAINT chk_sale_items_subtotal_non_negative
                    CHECK (subtotal_amount >= 0)
            )
        SQL);

        // ----------------------------------------------------------------
        // Indexes
        // ----------------------------------------------------------------
        // Primary access pattern: all items for a sale
        DB::statement('CREATE INDEX idx_sale_items_sale_id ON sale_items (sale_id)');

        // Product-level reporting: all sales of a given product
        DB::statement('CREATE INDEX idx_sale_items_product_id ON sale_items (product_id)');

        // ----------------------------------------------------------------
        // Role grants
        // ----------------------------------------------------------------
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON sale_items TO app_role');
        DB::statement('GRANT SELECT ON sale_items TO report_role');
        DB::statement('GRANT SELECT ON sale_items TO worker_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS sale_items CASCADE');
    }
};
