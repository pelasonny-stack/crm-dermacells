<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Distributor preferred cost table — §9.1
 *
 * DESIGN DECISIONS
 * ================
 * - modality ENUM restricts to the two models defined in §9.1:
 *   fixed_price (USD 500/caja) or discount_pct (e.g. 20% off base).
 * - value is NUMERIC(18,4) and is interpreted differently per modality:
 *   fixed_price → absolute price per box in `currency`
 *   discount_pct → fraction 0–1 (e.g. 0.2000 = 20%)
 * - UNIQUE (distributor_id, product_id): one active config per pair.
 *   Director overrides are done via UPDATE, not INSERT (last-write-wins).
 * - updated_by FK captures which Director made the change; combined with
 *   the audit_log trigger this gives full change history.
 * - No created_at — this table holds the *current* preferred cost only.
 *   Historical values live in audit_log via the AuditObserver on the model.
 *
 * GRANTS
 * ======
 * - app_role: SELECT, INSERT, UPDATE (no DELETE — use NULL/update instead)
 * - report_role: SELECT
 * - worker_role: SELECT (needed by RecalculateDistributorAccountJob)
 *
 * RLS is applied in migration 2026_05_09_000006_enable_distributor_finance_rls.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ----------------------------------------------------------------
        // 1. preferred_cost_modality ENUM
        // ----------------------------------------------------------------
        DB::statement(<<<'SQL'
            DO $$ BEGIN
                CREATE TYPE preferred_cost_modality AS ENUM ('fixed_price','discount_pct');
            EXCEPTION
                WHEN duplicate_object THEN NULL;
            END $$
        SQL);

        // ----------------------------------------------------------------
        // 2. distributor_preferred_cost table
        // ----------------------------------------------------------------
        DB::statement(<<<'SQL'
            CREATE TABLE distributor_preferred_cost (
                id              UUID                    PRIMARY KEY DEFAULT gen_random_uuid(),

                distributor_id  UUID                    NOT NULL
                                REFERENCES users (id) ON DELETE RESTRICT,

                product_id      UUID                    NOT NULL
                                REFERENCES products (id) ON DELETE RESTRICT,

                modality        preferred_cost_modality NOT NULL,

                -- For fixed_price: price per box in `currency`.
                -- For discount_pct: fraction in [0, 1].
                value           NUMERIC(18,4)           NOT NULL,

                -- Only relevant for fixed_price modality; discount_pct is always
                -- applied against the product's base_price_currency.
                currency        CHAR(3)                 NOT NULL DEFAULT 'USD',

                updated_by      UUID                    NOT NULL
                                REFERENCES users (id) ON DELETE RESTRICT,

                updated_at      TIMESTAMPTZ             NULL,

                -- Discount must be a fraction in [0,1]
                CONSTRAINT chk_dpc_discount_pct_range
                    CHECK (
                        modality = 'fixed_price'
                        OR (modality = 'discount_pct' AND value >= 0 AND value <= 1)
                    ),

                -- Fixed price must be positive
                CONSTRAINT chk_dpc_fixed_price_positive
                    CHECK (
                        modality = 'discount_pct'
                        OR (modality = 'fixed_price' AND value > 0)
                    ),

                -- One cost config per (distributor, product) pair
                CONSTRAINT uq_dpc_distributor_product
                    UNIQUE (distributor_id, product_id)
            )
        SQL);

        // ----------------------------------------------------------------
        // 3. Indexes
        // ----------------------------------------------------------------
        DB::statement('CREATE INDEX idx_dpc_distributor_id ON distributor_preferred_cost (distributor_id)');
        DB::statement('CREATE INDEX idx_dpc_product_id ON distributor_preferred_cost (product_id)');

        // ----------------------------------------------------------------
        // 4. Role grants
        // ----------------------------------------------------------------
        DB::statement('GRANT SELECT, INSERT, UPDATE ON distributor_preferred_cost TO app_role');
        DB::statement('GRANT SELECT ON distributor_preferred_cost TO report_role');
        DB::statement('GRANT SELECT ON distributor_preferred_cost TO worker_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS distributor_preferred_cost CASCADE');
        DB::statement('DROP TYPE IF EXISTS preferred_cost_modality CASCADE');
    }
};
