<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Idempotency keys table — Stripe-style deduplication for POST /sales,
 * /payments and /invoices (Phase 0 decision, §Idempotencia).
 *
 * DESIGN
 * ======
 * - id is a BIGSERIAL (not UUID) for insert-only high-volume rows where
 *   sequential ordering by creation is relevant for cleanup jobs.
 * - user_id + key is UNIQUE: the same key can be used by different users
 *   without collision, matching Stripe's per-user scoping behaviour.
 * - request_body_hash is SHA-256 hex (64 chars) of the raw request body.
 *   If a second request arrives with the same (user_id, key) but a different
 *   body hash, the middleware returns 422 IDEMPOTENCY_KEY_BODY_MISMATCH.
 * - response_body stores the full JSON response as text; replayed requests
 *   deserialize this string without re-executing the action.
 * - TTL: expires_at is set to created_at + 24h by the middleware.
 *   A scheduled cleanup job (Phase 16) prunes expired rows.
 * - request_path is stored for observability (which endpoint was called).
 *
 * No RLS needed: the middleware always scopes queries by user_id, and
 * response_body may contain sensitive data so no cross-user reads are possible
 * through the application layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE idempotency_keys (
                id                  BIGSERIAL       PRIMARY KEY,

                user_id             UUID            NOT NULL
                                    REFERENCES users (id) ON DELETE CASCADE,

                key                 UUID            NOT NULL,

                request_path        TEXT            NULL,

                -- SHA-256 hex of raw request body (always 64 hex chars)
                request_body_hash   CHAR(64)        NULL,

                -- Full JSON response body for replay
                response_body       TEXT            NULL,

                response_status     INTEGER         NULL,

                created_at          TIMESTAMPTZ     NULL,

                -- 24h TTL per Phase 0 idempotency spec
                expires_at          TIMESTAMPTZ     NULL,

                CONSTRAINT chk_idempotency_keys_status_valid
                    CHECK (response_status IS NULL OR (response_status >= 100 AND response_status < 600)),

                CONSTRAINT uq_idempotency_keys_user_key
                    UNIQUE (user_id, key)
            )
        SQL);

        // ----------------------------------------------------------------
        // Indexes
        // ----------------------------------------------------------------
        // Primary lookup: (user_id, key) is already covered by the UNIQUE constraint.
        // Expiry cleanup job scans by expires_at.
        DB::statement('CREATE INDEX idx_idempotency_keys_expires_at ON idempotency_keys (expires_at)');

        // ----------------------------------------------------------------
        // Role grants — middleware uses SELECT + INSERT + UPDATE (to persist response)
        // ----------------------------------------------------------------
        DB::statement('GRANT SELECT, INSERT, UPDATE ON idempotency_keys TO app_role');
        DB::statement('GRANT USAGE, SELECT ON SEQUENCE idempotency_keys_id_seq TO app_role');
        DB::statement('GRANT SELECT ON idempotency_keys TO report_role');
        DB::statement('GRANT SELECT, DELETE ON idempotency_keys TO worker_role');
        DB::statement('GRANT USAGE, SELECT ON SEQUENCE idempotency_keys_id_seq TO worker_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS idempotency_keys CASCADE');
    }
};
