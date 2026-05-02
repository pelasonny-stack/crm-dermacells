<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13 — ai_audit table (§11.5 "Todas las interacciones quedan
 * registradas en el log de auditoría").
 *
 * One row per AI request/response. Stores the request payload, the raw
 * response, and any leak detection metadata produced by LeakGuard.
 *
 * leak_detected = TRUE rows are the smoking-gun evidence that a model
 * tried to mention another customer's UUID. The Director reviews these
 * via Filament and may consider tightening the system prompt or
 * downgrading the model.
 *
 * This table does NOT use the audit_log infrastructure (HMAC chain +
 * monthly partitions) because:
 *   - Volume is per-AI-call which can spike beyond audit_log's append-only
 *     guarantees comfortable budget.
 *   - The semantic is "model behaviour evidence", not "user mutation".
 *   - HMAC integrity is not required for retraining feedback signals.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE ai_audit (
                id                  UUID            PRIMARY KEY DEFAULT gen_random_uuid(),
                user_id             UUID            NOT NULL
                                    REFERENCES users (id) ON DELETE RESTRICT,
                customer_id         UUID            NULL,
                request_payload     JSONB           NULL,
                response_payload    JSONB           NULL,
                leak_detected       BOOLEAN         NOT NULL DEFAULT FALSE,
                leak_details        TEXT            NULL,
                created_at          TIMESTAMPTZ     NOT NULL DEFAULT now()
            )
        SQL);

        DB::statement('CREATE INDEX idx_ai_audit_user ON ai_audit (user_id, created_at DESC)');
        DB::statement('CREATE INDEX idx_ai_audit_leak ON ai_audit (leak_detected, created_at DESC) WHERE leak_detected = TRUE');

        DB::statement('GRANT SELECT, INSERT ON ai_audit TO app_role');
        DB::statement('GRANT SELECT, INSERT ON ai_audit TO worker_role');
        DB::statement('GRANT SELECT ON ai_audit TO report_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS ai_audit CASCADE');
    }
};
