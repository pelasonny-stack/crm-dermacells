<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Distributor settlements table — §9.4
 *
 * DESIGN DECISIONS
 * ================
 * - A rendición is always initiated by the Distributor and confirmed by a Director.
 * - amount_amount / amount_currency: dual-column Money pattern consistent with
 *   the rest of the codebase. Each settlement is in a single currency (the
 *   Distributor renders ARS or USD in a given transaction, never both at once).
 * - payment_method_id is NULLABLE with a deferred FK comment because Phase 7
 *   (payments) is being built in parallel; a FK will be added when that table
 *   stabilises. Application layer must be defensive (lazy query guard).
 * - status ENUM pending → confirmed/rejected. Only confirmed settlements reduce
 *   the distributor's balance.
 * - submitted_at defaults to now() so the Distributor does not supply it;
 *   confirmed_at is set by the Director action.
 *
 * RLS applied in 2026_05_09_000006.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ----------------------------------------------------------------
        // 1. settlement_status ENUM
        // ----------------------------------------------------------------
        DB::statement(<<<'SQL'
            DO $$ BEGIN
                CREATE TYPE settlement_status AS ENUM ('pending','confirmed','rejected');
            EXCEPTION
                WHEN duplicate_object THEN NULL;
            END $$
        SQL);

        // ----------------------------------------------------------------
        // 2. distributor_settlements table
        // ----------------------------------------------------------------
        DB::statement(<<<'SQL'
            CREATE TABLE distributor_settlements (
                id                  UUID                PRIMARY KEY DEFAULT gen_random_uuid(),

                distributor_id      UUID                NOT NULL
                                    REFERENCES users (id) ON DELETE RESTRICT,

                -- Money (dual-column; single currency per rendición)
                amount_amount       NUMERIC(18,4)       NOT NULL,
                amount_currency     CHAR(3)             NOT NULL,

                -- Deferred link to Phase 7 payments table.
                -- NOT a foreign key yet — added via ALTER TABLE in Phase 7 finalisation.
                payment_method_id   UUID                NULL,

                reference           TEXT                NULL,

                submitted_at        TIMESTAMPTZ         NOT NULL DEFAULT now(),

                confirmed_by        UUID                NULL
                                    REFERENCES users (id) ON DELETE SET NULL,

                confirmed_at        TIMESTAMPTZ         NULL,

                status              settlement_status   NOT NULL DEFAULT 'pending',

                notes               TEXT                NULL,

                created_at          TIMESTAMPTZ         NULL,
                updated_at          TIMESTAMPTZ         NULL,

                -- Amount must be positive
                CONSTRAINT chk_ds_amount_positive
                    CHECK (amount_amount > 0),

                -- Currency must be ARS or USD
                CONSTRAINT chk_ds_currency
                    CHECK (amount_currency IN ('ARS','USD')),

                -- Confirmation metadata must be consistent
                CONSTRAINT chk_ds_confirmed_consistency
                    CHECK (
                        (status = 'confirmed' AND confirmed_by IS NOT NULL AND confirmed_at IS NOT NULL)
                        OR status != 'confirmed'
                    )
            )
        SQL);

        // ----------------------------------------------------------------
        // 3. Indexes
        // ----------------------------------------------------------------
        DB::statement('CREATE INDEX idx_ds_distributor_id ON distributor_settlements (distributor_id)');
        DB::statement('CREATE INDEX idx_ds_status ON distributor_settlements (status)');
        DB::statement('CREATE INDEX idx_ds_submitted_at ON distributor_settlements (submitted_at DESC)');

        // ----------------------------------------------------------------
        // 4. Grants
        // ----------------------------------------------------------------
        DB::statement('GRANT SELECT, INSERT, UPDATE ON distributor_settlements TO app_role');
        DB::statement('GRANT SELECT ON distributor_settlements TO report_role');
        DB::statement('GRANT SELECT ON distributor_settlements TO worker_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS distributor_settlements CASCADE');
        DB::statement('DROP TYPE IF EXISTS settlement_status CASCADE');
    }
};
