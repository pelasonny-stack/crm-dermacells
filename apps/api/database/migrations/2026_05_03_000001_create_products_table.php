<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Products table — §5.1, §16.4.
 *
 * Stores the four LiveCells product lines (Dermal, Pink, Capillary, Biomask)
 * plus any future additions the Director configures from the admin panel.
 *
 * Money storage: NUMERIC(18,4) amount + CHAR(3) currency, read by MoneyCast
 * into Brick\Money\Money. Default currency is USD (§5.1 prices in USD).
 *
 * The UNIQUE(name) constraint powers idempotent seeding via ON CONFLICT DO NOTHING.
 *
 * RLS model:
 *   - All authenticated roles SELECT (products are reference data for sales).
 *   - INSERT/UPDATE/DELETE restricted to Director role only (§16.4).
 *   - worker_role and report_role: unrestricted SELECT (background jobs and reports).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE products (
                id                      UUID            PRIMARY KEY DEFAULT gen_random_uuid(),
                name                    TEXT            NOT NULL,
                units_per_box           INTEGER         NOT NULL DEFAULT 5,
                base_price_amount       NUMERIC(18,4)   NOT NULL,
                base_price_currency     CHAR(3)         NOT NULL DEFAULT 'USD',
                is_active               BOOLEAN         NOT NULL DEFAULT TRUE,
                created_at              TIMESTAMPTZ     NULL,
                updated_at              TIMESTAMPTZ     NULL,

                CONSTRAINT uq_products_name
                    UNIQUE (name),

                CONSTRAINT chk_products_units_per_box_positive
                    CHECK (units_per_box > 0),

                CONSTRAINT chk_products_base_price_positive
                    CHECK (base_price_amount > 0),

                CONSTRAINT chk_products_currency_length
                    CHECK (length(base_price_currency) = 3)
            )
        SQL);

        DB::statement('CREATE INDEX idx_products_is_active ON products (is_active)');

        // ----------------------------------------------------------------
        // Role grants
        // ----------------------------------------------------------------
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON products TO app_role');
        DB::statement('GRANT SELECT ON products TO report_role');
        DB::statement('GRANT SELECT ON products TO worker_role');

        // ----------------------------------------------------------------
        // Row Level Security
        // ----------------------------------------------------------------
        DB::statement('ALTER TABLE products ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE products FORCE ROW LEVEL SECURITY');

        // All authenticated users can read products (needed for creating sales).
        DB::statement(<<<'SQL'
            CREATE POLICY products_read_all ON products
            AS PERMISSIVE FOR SELECT
            TO app_role
            USING (TRUE)
        SQL);

        // Only Directors can INSERT or UPDATE products (§16.4).
        DB::statement(<<<'SQL'
            CREATE POLICY products_director_write ON products
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'director'
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'director'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY products_worker_read ON products
            AS PERMISSIVE FOR SELECT
            TO worker_role
            USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY products_report_read ON products
            AS PERMISSIVE FOR SELECT
            TO report_role
            USING (TRUE)
        SQL);
    }

    public function down(): void
    {
        foreach (['products_read_all', 'products_director_write', 'products_worker_read', 'products_report_read'] as $policy) {
            DB::statement("DROP POLICY IF EXISTS {$policy} ON products");
        }

        DB::statement('ALTER TABLE products DISABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE products NO FORCE ROW LEVEL SECURITY');
        DB::statement('DROP TABLE IF EXISTS products CASCADE');
    }
};
