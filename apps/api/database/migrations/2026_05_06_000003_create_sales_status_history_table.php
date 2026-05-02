<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sales status history table — immutable audit trail for state transitions.
 *
 * Every call to a state-transition Action (Confirm, Deliver, Cancel) appends
 * one row here via SalesStatusHistory::create(). The table is append-only:
 * no UPDATE or DELETE is granted to app_role (same principle as audit_log).
 *
 * from_status is NULL for the initial "draft created" event (transition from
 * nothing to 'draft'). This matches the convention that the very first row for
 * a sale records who created it and when, mirroring the audit_log HMAC pattern.
 *
 * changed_at is stored as TIMESTAMPTZ with DEFAULT now() so the DB clock is
 * authoritative even if the application server has clock skew.
 *
 * Indexes:
 *   - (sale_id, changed_at DESC) — fetch ordered history for a given sale.
 *   - (changed_by)               — Director audit: all transitions by a user.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE sales_status_history (
                id              UUID        PRIMARY KEY DEFAULT gen_random_uuid(),

                sale_id         UUID        NOT NULL
                                REFERENCES sales (id) ON DELETE CASCADE,

                from_status     TEXT        NULL,
                to_status       TEXT        NOT NULL,

                changed_by      UUID        NOT NULL
                                REFERENCES users (id) ON DELETE RESTRICT,

                changed_at      TIMESTAMPTZ NOT NULL DEFAULT now(),

                note            TEXT        NULL
            )
        SQL);

        // ----------------------------------------------------------------
        // Indexes
        // ----------------------------------------------------------------
        DB::statement('CREATE INDEX idx_sales_status_history_sale_id ON sales_status_history (sale_id, changed_at DESC)');
        DB::statement('CREATE INDEX idx_sales_status_history_changed_by ON sales_status_history (changed_by)');

        // ----------------------------------------------------------------
        // Role grants — append-only (no UPDATE/DELETE for app_role)
        // ----------------------------------------------------------------
        DB::statement('GRANT SELECT, INSERT ON sales_status_history TO app_role');
        DB::statement('GRANT SELECT ON sales_status_history TO report_role');
        DB::statement('GRANT SELECT ON sales_status_history TO worker_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS sales_status_history CASCADE');
    }
};
