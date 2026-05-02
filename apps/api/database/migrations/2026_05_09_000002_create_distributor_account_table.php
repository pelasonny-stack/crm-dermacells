<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Distributor account table — §9.3
 *
 * DESIGN DECISIONS
 * ================
 * - One row per Distributor (UNIQUE distributor_id). The row is created lazily
 *   by DistributorAccountObserver when a Director assigns a user as a
 *   Distributor to a zone for the first time.
 * - balance_ars / balance_usd: current saldo a rendir (what the Distributor
 *   owes Dermacells) in each currency. Updated by RecalculateDistributorAccountJob.
 * - gross_margin_ars / gross_margin_usd: Ventas zona − costo preferencial,
 *   before commissions. Stored for fast dashboard reads.
 * - last_recalculated_at: timestamp of the last successful full recalculation
 *   so the UI can show "as of" and warn if stale.
 * - No created_at (single-row-per-distributor, lifespan = distributor lifetime).
 *
 * TRIGGERS / OBSERVERS
 * ====================
 * Row creation is handled by DistributorAccountObserver (Phase 8 listener on
 * zone assignments). The job RecalculateDistributorAccountJob computes values
 * idempotently and UPDATEs this row.
 *
 * RLS applied in 2026_05_09_000006_enable_distributor_finance_rls.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE distributor_account (
                id                      UUID            PRIMARY KEY DEFAULT gen_random_uuid(),

                distributor_id          UUID            NOT NULL
                                        REFERENCES users (id) ON DELETE RESTRICT,

                -- Saldo a rendir by currency (never converted)
                balance_ars             NUMERIC(18,4)   NOT NULL DEFAULT 0,
                balance_usd             NUMERIC(18,4)   NOT NULL DEFAULT 0,

                -- Margen bruto (ventas - costo preferencial) by currency
                gross_margin_ars        NUMERIC(18,4)   NOT NULL DEFAULT 0,
                gross_margin_usd        NUMERIC(18,4)   NOT NULL DEFAULT 0,

                last_recalculated_at    TIMESTAMPTZ     NULL,

                updated_at              TIMESTAMPTZ     NULL,

                -- Exactly one account per distributor
                CONSTRAINT uq_da_distributor_id
                    UNIQUE (distributor_id)
            )
        SQL);

        // Index on distributor_id for FK-style lookups
        DB::statement('CREATE INDEX idx_da_distributor_id ON distributor_account (distributor_id)');

        DB::statement('GRANT SELECT, INSERT, UPDATE ON distributor_account TO app_role');
        DB::statement('GRANT SELECT ON distributor_account TO report_role');
        DB::statement('GRANT SELECT ON distributor_account TO worker_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS distributor_account CASCADE');
    }
};
