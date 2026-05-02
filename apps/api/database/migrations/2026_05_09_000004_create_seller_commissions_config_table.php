<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seller commissions config table — §9.2
 *
 * DESIGN DECISIONS
 * ================
 * - Versioned: the UNIQUE (distributor_id, seller_id, zone_id, effective_from)
 *   constraint allows the Distributor to change the percentage by inserting a
 *   new row with a later effective_from. CommissionAssignmentService resolves
 *   the active row via MAX(effective_from) <= target_date.
 * - commission_pct is stored as a fraction in [0, 1] with NUMERIC(5,4) giving
 *   0.0000–1.0000 precision (e.g. 0.1500 = 15%).
 * - zone_id: a Seller can operate in multiple zones; each Distributor of each
 *   zone fixes and pays independently (§9.2).
 * - set_by: audit FK to the user who last set this configuration.
 * - effective_from DATE (not TIMESTAMPTZ) because commission periods are
 *   monthly and the effective date is always a calendar date.
 *
 * DB-level CHECK enforces the [0,1] range; the application also validates
 * before insert to provide friendly error messages.
 *
 * RLS applied in 2026_05_09_000006.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE seller_commissions_config (
                id              UUID            PRIMARY KEY DEFAULT gen_random_uuid(),

                distributor_id  UUID            NOT NULL
                                REFERENCES users (id) ON DELETE RESTRICT,

                seller_id       UUID            NOT NULL
                                REFERENCES users (id) ON DELETE RESTRICT,

                commission_pct  NUMERIC(5,4)    NOT NULL,

                zone_id         UUID            NOT NULL
                                REFERENCES zones (id) ON DELETE RESTRICT,

                set_by          UUID            NOT NULL
                                REFERENCES users (id) ON DELETE RESTRICT,

                effective_from  DATE            NOT NULL,

                created_at      TIMESTAMPTZ     NULL,
                updated_at      TIMESTAMPTZ     NULL,

                -- Percentage fraction in [0, 1]
                CONSTRAINT chk_scc_pct_range
                    CHECK (commission_pct >= 0 AND commission_pct <= 1),

                -- Versioned uniqueness: one config per (distributor, seller, zone, date)
                CONSTRAINT uq_scc_versioned
                    UNIQUE (distributor_id, seller_id, zone_id, effective_from)
            )
        SQL);

        // ----------------------------------------------------------------
        // Indexes
        // ----------------------------------------------------------------
        // Primary lookup: what pct does distributor D pay seller S in zone Z?
        DB::statement('CREATE INDEX idx_scc_lookup ON seller_commissions_config (distributor_id, seller_id, zone_id, effective_from DESC)');
        // Secondary: list all configs for a distributor
        DB::statement('CREATE INDEX idx_scc_distributor ON seller_commissions_config (distributor_id)');
        // Tertiary: list all commissions a seller earns
        DB::statement('CREATE INDEX idx_scc_seller ON seller_commissions_config (seller_id)');

        DB::statement('GRANT SELECT, INSERT, UPDATE ON seller_commissions_config TO app_role');
        DB::statement('GRANT SELECT ON seller_commissions_config TO report_role');
        DB::statement('GRANT SELECT ON seller_commissions_config TO worker_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS seller_commissions_config CASCADE');
    }
};
