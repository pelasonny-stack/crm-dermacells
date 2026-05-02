<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the scheduled_actions table — acciones futuras programadas (§3.8).
 *
 * A scheduled action allows any role (within their customer scope) to attach a
 * future date + note to a customer, differentiating them from inactive-by-
 * abandonment customers. On the scheduled_date the system fires a push to the
 * assigned Seller.
 *
 * LIFECYCLE
 * =========
 * - is_resolved = false  →  action is pending; customer shows as "en seguimiento programado".
 * - is_resolved = true   →  action completed; resolved_at records when.
 *
 * PARTIAL INDEX on (scheduled_date, is_resolved) WHERE is_resolved = false:
 *   The daily dispatch command queries ONLY unresolved future actions. With
 *   most actions resolved over time, this index stays small and the scan is
 *   constant-time regardless of historical row count.
 *
 * There is no UNIQUE constraint on (customer_id, scheduled_date) because a
 * customer can legitimately have multiple pending actions (e.g. call + visit on
 * the same day). Deduplication is a UX concern handled by the application layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE scheduled_actions (
                id             UUID        NOT NULL PRIMARY KEY DEFAULT gen_random_uuid(),
                customer_id    UUID        NOT NULL
                               REFERENCES customers (id) ON DELETE CASCADE,
                created_by     UUID        NOT NULL
                               REFERENCES users (id),
                scheduled_date DATE        NOT NULL,
                note           TEXT        NOT NULL,
                is_resolved    BOOLEAN     NOT NULL DEFAULT FALSE,
                resolved_at    TIMESTAMPTZ NULL,
                created_at     TIMESTAMPTZ NULL,
                updated_at     TIMESTAMPTZ NULL,

                -- A resolved action must have a resolved_at timestamp
                CONSTRAINT chk_scheduled_actions_resolved
                    CHECK (
                        (is_resolved = FALSE AND resolved_at IS NULL)
                        OR (is_resolved = TRUE AND resolved_at IS NOT NULL)
                    )
            )
        SQL);

        DB::statement('CREATE INDEX idx_scheduled_actions_customer_id ON scheduled_actions (customer_id)');
        DB::statement('CREATE INDEX idx_scheduled_actions_created_by ON scheduled_actions (created_by)');

        // Partial index for daily dispatch: only unresolved rows, ordered by date
        DB::statement(<<<'SQL'
            CREATE INDEX idx_scheduled_actions_pending_by_date
                ON scheduled_actions (scheduled_date, customer_id)
                WHERE is_resolved = FALSE
        SQL);

        // Role grants
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON scheduled_actions TO app_role');
        DB::statement('GRANT SELECT ON scheduled_actions TO report_role');
        DB::statement('GRANT SELECT ON scheduled_actions TO worker_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS scheduled_actions CASCADE');
    }
};
