<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Exchange rates table — §5.3, §16.7.
 *
 * One row per calendar day. The UNIQUE(rate_date) constraint ensures at most
 * one authoritative rate per day — FetchBcraExchangeRateJob uses updateOrCreate
 * keyed on rate_date.
 *
 * Source enum values (enforced by CHECK):
 *   - 'api_bna'         : fetched successfully from BCRA estadísticas cambiarias
 *   - 'manual_override' : Director entered a value manually via Filament (§16.7)
 *   - 'fallback'        : BCRA API was unavailable; previous day's rate was copied
 *
 * recorded_by is NULL for API-sourced rates (no human actor) and populated
 * with the Director's UUID for manual_override entries (audit trail).
 *
 * RLS model: all authenticated roles can SELECT (rate is needed for every ARS sale).
 * Only Directors can INSERT/UPDATE (includes both manual_override and the worker job
 * running as director system user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE exchange_rates (
                id                  UUID            PRIMARY KEY DEFAULT gen_random_uuid(),
                rate_date           DATE            NOT NULL,
                rate_ars_per_usd    NUMERIC(18,6)   NOT NULL,
                source              TEXT            NOT NULL,
                recorded_by         UUID            NULL REFERENCES users (id) ON DELETE SET NULL,
                created_at          TIMESTAMPTZ     NULL,

                CONSTRAINT uq_exchange_rates_date
                    UNIQUE (rate_date),

                CONSTRAINT chk_exchange_rates_source
                    CHECK (source IN ('api_bna', 'manual_override', 'fallback')),

                CONSTRAINT chk_exchange_rates_positive
                    CHECK (rate_ars_per_usd > 0)
            )
        SQL);

        // Most queries fetch by rate_date DESC to get the latest rate.
        DB::statement('CREATE INDEX idx_exchange_rates_date_desc ON exchange_rates (rate_date DESC)');

        // ----------------------------------------------------------------
        // Role grants
        // ----------------------------------------------------------------
        DB::statement('GRANT SELECT, INSERT, UPDATE ON exchange_rates TO app_role');
        DB::statement('GRANT SELECT ON exchange_rates TO report_role');
        DB::statement('GRANT SELECT, INSERT, UPDATE ON exchange_rates TO worker_role');

        // ----------------------------------------------------------------
        // Row Level Security
        // ----------------------------------------------------------------
        DB::statement('ALTER TABLE exchange_rates ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE exchange_rates FORCE ROW LEVEL SECURITY');

        // All authenticated roles can read exchange rates.
        DB::statement(<<<'SQL'
            CREATE POLICY exchange_rates_read_all ON exchange_rates
            AS PERMISSIVE FOR SELECT
            TO app_role
            USING (TRUE)
        SQL);

        // Only Directors can write exchange rates (manual override + API sync).
        DB::statement(<<<'SQL'
            CREATE POLICY exchange_rates_director_write ON exchange_rates
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'director'
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'director'
            )
        SQL);

        // worker_role (Horizon) writes the daily rate fetched from BCRA.
        DB::statement(<<<'SQL'
            CREATE POLICY exchange_rates_worker_write ON exchange_rates
            AS PERMISSIVE FOR ALL
            TO worker_role
            USING (TRUE)
            WITH CHECK (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY exchange_rates_report_read ON exchange_rates
            AS PERMISSIVE FOR SELECT
            TO report_role
            USING (TRUE)
        SQL);
    }

    public function down(): void
    {
        foreach ([
            'exchange_rates_read_all',
            'exchange_rates_director_write',
            'exchange_rates_worker_write',
            'exchange_rates_report_read',
        ] as $policy) {
            DB::statement("DROP POLICY IF EXISTS {$policy} ON exchange_rates");
        }

        DB::statement('ALTER TABLE exchange_rates DISABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE exchange_rates NO FORCE ROW LEVEL SECURITY');
        DB::statement('DROP TABLE IF EXISTS exchange_rates CASCADE');
    }
};
