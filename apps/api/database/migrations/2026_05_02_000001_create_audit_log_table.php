<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the audit_log table as a RANGE-partitioned (monthly) append-only
 * immutable ledger per §16.13 of the Dermacells spec.
 *
 * Immutability is enforced at three layers:
 *   1. Postgres BEFORE UPDATE OR DELETE trigger raises an exception.
 *   2. REVOKE UPDATE, DELETE, TRUNCATE FROM app_role — the runtime role
 *      used by the application cannot physically mutate rows.
 *   3. HMAC-SHA256 chain (prev_hash → row_hash) enforced by the
 *      AuditObserver at the application layer (Phase 1).
 *
 * GUCs read by RLS policies on this table (set per-transaction by
 * SetPostgresRlsContext middleware):
 *   - app.user_id   (uuid of the authenticated user)
 *   - app.user_role (director | distributor | seller)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ----------------------------------------------------------------
        // 1. Parent partitioned table
        // ----------------------------------------------------------------
        DB::statement(<<<'SQL'
            CREATE TABLE audit_log (
                id             BIGSERIAL       NOT NULL,
                occurred_at    TIMESTAMPTZ     NOT NULL DEFAULT NOW(),
                actor_user_id  UUID            NULL,
                actor_role     TEXT            NULL,
                section        TEXT            NULL,
                entity_type    TEXT            NULL,
                entity_id      TEXT            NULL,
                field_name     TEXT            NULL,
                old_value      TEXT            NULL,
                new_value      TEXT            NULL,
                ip_address     INET            NULL,
                session_id     TEXT            NULL,
                prev_hash      CHAR(64)        NOT NULL DEFAULT REPEAT('0', 64),
                row_hash       CHAR(64)        NOT NULL,
                PRIMARY KEY (id, occurred_at)
            ) PARTITION BY RANGE (occurred_at)
        SQL);

        // ----------------------------------------------------------------
        // 2. BEFORE UPDATE OR DELETE trigger — raises immediately, before
        //    any row is touched, making the table append-only at the DB level
        // ----------------------------------------------------------------
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_log_immutable()
            RETURNS TRIGGER LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION
                    'audit_log is immutable — UPDATE and DELETE are forbidden (§16.13)';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_audit_log_immutable
            BEFORE UPDATE OR DELETE ON audit_log
            FOR EACH ROW EXECUTE FUNCTION audit_log_immutable()
        SQL);

        // ----------------------------------------------------------------
        // 3. Revoke mutating privileges from the runtime role.
        //    TRUNCATE is also revoked so pg_partman or manual maintenance
        //    cannot accidentally wipe partitions via the app role.
        // ----------------------------------------------------------------
        DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON audit_log FROM app_role');

        // ----------------------------------------------------------------
        // 4. Grant INSERT + SELECT to the runtime role (and worker role)
        // ----------------------------------------------------------------
        DB::statement('GRANT INSERT, SELECT ON audit_log TO app_role');
        DB::statement('GRANT SELECT ON audit_log TO report_role');
        DB::statement('GRANT INSERT, SELECT ON audit_log TO worker_role');
        DB::statement('GRANT USAGE, SELECT ON SEQUENCE audit_log_id_seq TO app_role');
        DB::statement('GRANT USAGE, SELECT ON SEQUENCE audit_log_id_seq TO worker_role');

        // ----------------------------------------------------------------
        // 5. Initial monthly partitions: current month + next 3 months
        //    Partitions are named audit_log_YYYY_MM for easy identification
        //    and for the artisan command that creates future ones.
        // ----------------------------------------------------------------
        $this->createMonthlyPartitions(4);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS audit_log CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS audit_log_immutable() CASCADE');
    }

    // ----------------------------------------------------------------
    // Helper: create N monthly partitions starting from the current month
    // ----------------------------------------------------------------
    private function createMonthlyPartitions(int $count): void
    {
        $base = new \DateTimeImmutable('first day of this month midnight UTC');

        for ($i = 0; $i < $count; $i++) {
            $start = $base->modify("+{$i} months");
            $end   = $start->modify('+1 month');

            $partitionName = 'audit_log_' . $start->format('Y_m');
            $startStr      = $start->format('Y-m-d');
            $endStr        = $end->format('Y-m-d');

            DB::statement(<<<SQL
                CREATE TABLE IF NOT EXISTS {$partitionName}
                PARTITION OF audit_log
                FOR VALUES FROM ('{$startStr}') TO ('{$endStr}')
            SQL);

            // Indexes per partition (not inherited automatically for
            // partitioned tables in Postgres < 17; safe to run on each).
            DB::statement(<<<SQL
                CREATE INDEX IF NOT EXISTS {$partitionName}_actor_occurred_idx
                ON {$partitionName} (actor_user_id, occurred_at)
            SQL);

            DB::statement(<<<SQL
                CREATE INDEX IF NOT EXISTS {$partitionName}_entity_idx
                ON {$partitionName} (entity_type, entity_id)
            SQL);

            DB::statement(<<<SQL
                CREATE INDEX IF NOT EXISTS {$partitionName}_section_idx
                ON {$partitionName} (section)
            SQL);
        }
    }
};
