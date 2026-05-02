<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Commission scale table — §12.2, §16.5.
 *
 * Stores the versioned global commission tier ladder for Sellers. Tiers are
 * effective from a specific date — new rows are added when the Director
 * reconfigures the scale; historical rows are never mutated (audit via
 * effective_from versioning).
 *
 * Per §12.2 there are initially three tiers:
 *   tier_order=1  threshold=0       rate=10%  (USD 0 – 7,500)
 *   tier_order=2  threshold=7500    rate=12%  (USD 7,500 – 11,250)
 *   tier_order=3  threshold=11250   rate=15%  (USD > 11,250)
 *
 * The threshold_amount is the floor of the tier in USD-equivalent monthly
 * collections. The CommissionCalculatorService (Phase 9) resolves the active
 * tier set for a given date by reading rows WHERE effective_from <= target_date
 * ORDER BY effective_from DESC, tier_order ASC and taking the latest set.
 *
 * Money storage: NUMERIC(18,4) + CHAR(3) — consistent with products and
 * exchange_rates. Default currency USD.
 *
 * RLS model: Director-only for all DML and SELECT (commission scale is
 * sensitive configuration data not exposed to Sellers or Distributors).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE commission_scale (
                id                      UUID            PRIMARY KEY DEFAULT gen_random_uuid(),
                tier_order              INTEGER         NOT NULL,
                threshold_amount        NUMERIC(18,4)   NOT NULL,
                threshold_currency      CHAR(3)         NOT NULL DEFAULT 'USD',
                rate_pct                NUMERIC(5,4)    NOT NULL,
                effective_from          DATE            NOT NULL,
                created_by              UUID            NULL REFERENCES users (id) ON DELETE SET NULL,
                created_at              TIMESTAMPTZ     NULL,
                updated_at              TIMESTAMPTZ     NULL,

                CONSTRAINT chk_commission_scale_tier_order_positive
                    CHECK (tier_order > 0),

                CONSTRAINT chk_commission_scale_threshold_non_negative
                    CHECK (threshold_amount >= 0),

                CONSTRAINT chk_commission_scale_rate_pct_range
                    CHECK (rate_pct > 0 AND rate_pct <= 1),

                CONSTRAINT chk_commission_scale_currency_length
                    CHECK (length(threshold_currency) = 3)
            )
        SQL);

        // The CommissionCalculatorService resolves the active tier set by
        // effective_from; index on this column for fast lookup.
        DB::statement('CREATE INDEX idx_commission_scale_effective_from ON commission_scale (effective_from)');

        // ----------------------------------------------------------------
        // Role grants — Director-only data
        // ----------------------------------------------------------------
        DB::statement('GRANT SELECT, INSERT, UPDATE ON commission_scale TO app_role');
        DB::statement('GRANT SELECT ON commission_scale TO report_role');
        DB::statement('GRANT SELECT ON commission_scale TO worker_role');

        // ----------------------------------------------------------------
        // Row Level Security — Director only for all operations
        // ----------------------------------------------------------------
        DB::statement('ALTER TABLE commission_scale ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE commission_scale FORCE ROW LEVEL SECURITY');

        // Directors have full access to read and write the commission scale.
        DB::statement(<<<'SQL'
            CREATE POLICY commission_scale_director_all ON commission_scale
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'director'
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'director'
            )
        SQL);

        // worker_role needs to read tiers for commission calculation jobs.
        DB::statement(<<<'SQL'
            CREATE POLICY commission_scale_worker_read ON commission_scale
            AS PERMISSIVE FOR SELECT
            TO worker_role
            USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY commission_scale_report_read ON commission_scale
            AS PERMISSIVE FOR SELECT
            TO report_role
            USING (TRUE)
        SQL);
    }

    public function down(): void
    {
        foreach ([
            'commission_scale_director_all',
            'commission_scale_worker_read',
            'commission_scale_report_read',
        ] as $policy) {
            DB::statement("DROP POLICY IF EXISTS {$policy} ON commission_scale");
        }

        DB::statement('ALTER TABLE commission_scale DISABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE commission_scale NO FORCE ROW LEVEL SECURITY');
        DB::statement('DROP TABLE IF EXISTS commission_scale CASCADE');
    }
};
