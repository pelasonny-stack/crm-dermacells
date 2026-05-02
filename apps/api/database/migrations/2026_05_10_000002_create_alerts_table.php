<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 10 — alerts table.
 *
 * Append-only alert log. Each row represents one alert dispatched to one
 * target user. AlertDispatcher handles fan-out when multiple targets receive
 * the same alert event.
 *
 * NOT auditable — this table IS the audit trail for alert delivery.
 * Payload stored as JSONB to allow arbitrary type-specific fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS alerts (
                id                      UUID        NOT NULL DEFAULT gen_random_uuid(),
                alert_type              TEXT        NOT NULL,
                target_user_id          UUID        NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                reference_entity_type   TEXT        NULL,
                reference_entity_id     UUID        NULL,
                payload_json            JSONB       NULL,
                severity                TEXT        NOT NULL DEFAULT 'info'
                                            CHECK (severity IN ('info', 'warning', 'critical')),
                delivered               BOOLEAN     NOT NULL DEFAULT FALSE,
                delivered_at            TIMESTAMPTZ NULL,
                read_at                 TIMESTAMPTZ NULL,
                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT alerts_pkey PRIMARY KEY (id)
            )
        SQL);

        // Primary query pattern: "my undelivered alerts"
        DB::statement('CREATE INDEX IF NOT EXISTS idx_alerts_target_delivered ON alerts (target_user_id, delivered)');
        // Alert type catalog query (Director view)
        DB::statement('CREATE INDEX IF NOT EXISTS idx_alerts_type ON alerts (alert_type)');
        // Idempotency check by (type, target, reference) + recency
        DB::statement('CREATE INDEX IF NOT EXISTS idx_alerts_idempotency ON alerts (alert_type, target_user_id, reference_entity_id, created_at DESC NULLS LAST)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS alerts CASCADE');
    }
};
