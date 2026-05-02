<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Distributor commission payments table — §9.2
 *
 * DESIGN DECISIONS
 * ================
 * - One row per (distributor, seller, zone, period_month) combination,
 *   generated monthly by ComputeMonthlyCommissionsJob.
 * - period_month is a DATE truncated to the first day of the month
 *   (e.g. 2026-05-01). Using DATE instead of YYYY-MM avoids type ambiguity
 *   and makes range queries straightforward.
 * - base_amount_*: sum of delivered sale subtotals in the zone for that month.
 * - commission_pct: snapshot of the active seller_commissions_config row at
 *   the time of computation. Stored here so historical payments are self-contained.
 * - commission_amount_*: base_amount * commission_pct, in the same currency
 *   as base_amount (never converted).
 * - paid / paid_at / paid_by / payment_reference: filled when the Distributor
 *   records the payment to the Seller.
 * - UNIQUE on (distributor_id, seller_id, zone_id, period_month) enforces
 *   idempotency — ComputeMonthlyCommissionsJob uses INSERT ... ON CONFLICT DO NOTHING.
 *
 * RLS applied in 2026_05_09_000006.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE distributor_commission_payments (
                id                          UUID            PRIMARY KEY DEFAULT gen_random_uuid(),

                -- Payer
                distributor_id              UUID            NOT NULL
                                            REFERENCES users (id) ON DELETE RESTRICT,

                -- Payee
                seller_id                   UUID            NOT NULL
                                            REFERENCES users (id) ON DELETE RESTRICT,

                -- Zone this commission relates to
                zone_id                     UUID            NOT NULL
                                            REFERENCES zones (id) ON DELETE RESTRICT,

                -- Month this commission covers (first day of the month)
                period_month                DATE            NOT NULL,

                -- Basis
                base_amount_amount          NUMERIC(18,4)   NOT NULL DEFAULT 0,
                base_amount_currency        CHAR(3)         NOT NULL,

                -- Snapshot of the rate applied
                commission_pct              NUMERIC(5,4)    NOT NULL,

                -- Computed amount
                commission_amount_amount    NUMERIC(18,4)   NOT NULL DEFAULT 0,
                commission_amount_currency  CHAR(3)         NOT NULL,

                -- Payment tracking
                paid                        BOOLEAN         NOT NULL DEFAULT FALSE,
                paid_at                     TIMESTAMPTZ     NULL,
                paid_by                     UUID            NULL
                                            REFERENCES users (id) ON DELETE SET NULL,
                payment_reference           TEXT            NULL,

                created_at                  TIMESTAMPTZ     NULL,
                updated_at                  TIMESTAMPTZ     NULL,

                -- Fraction range
                CONSTRAINT chk_dcp_pct_range
                    CHECK (commission_pct >= 0 AND commission_pct <= 1),

                -- Base amount non-negative
                CONSTRAINT chk_dcp_base_amount
                    CHECK (base_amount_amount >= 0),

                -- Commission amount non-negative
                CONSTRAINT chk_dcp_commission_amount
                    CHECK (commission_amount_amount >= 0),

                -- Currency coherence (base and commission in same currency)
                CONSTRAINT chk_dcp_currency_match
                    CHECK (base_amount_currency = commission_amount_currency),

                -- Idempotency: one row per (distributor, seller, zone, period)
                CONSTRAINT uq_dcp_period
                    UNIQUE (distributor_id, seller_id, zone_id, period_month),

                -- Paid metadata consistency
                CONSTRAINT chk_dcp_paid_consistency
                    CHECK (
                        (paid = TRUE AND paid_at IS NOT NULL AND paid_by IS NOT NULL)
                        OR paid = FALSE
                    )
            )
        SQL);

        // ----------------------------------------------------------------
        // Indexes
        // ----------------------------------------------------------------
        // Distributor dashboard: all commission payments they owe
        DB::statement('CREATE INDEX idx_dcp_distributor ON distributor_commission_payments (distributor_id, period_month DESC)');
        // Seller view: commissions they are owed
        DB::statement('CREATE INDEX idx_dcp_seller ON distributor_commission_payments (seller_id, period_month DESC)');
        // Job cleanup / reconciliation
        DB::statement('CREATE INDEX idx_dcp_period ON distributor_commission_payments (period_month)');
        // Unpaid scan
        DB::statement("CREATE INDEX idx_dcp_unpaid ON distributor_commission_payments (distributor_id, paid) WHERE paid = FALSE");

        DB::statement('GRANT SELECT, INSERT, UPDATE ON distributor_commission_payments TO app_role');
        DB::statement('GRANT SELECT ON distributor_commission_payments TO report_role');
        DB::statement('GRANT SELECT ON distributor_commission_payments TO worker_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS distributor_commission_payments CASCADE');
    }
};
