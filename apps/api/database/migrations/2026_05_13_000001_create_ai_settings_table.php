<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13 — ai_settings table (§11).
 *
 * Single-row configuration table for the optional AI Assistant module.
 * Row identity is enforced by a CHECK constraint pinning the primary
 * key to the all-ones UUID (`00000000-0000-0000-0000-000000000001`).
 * That guarantees there can never be a "second" settings row regardless
 * of any well-meaning accidental INSERT.
 *
 * The provider field is intentionally a free-text CHECK (not an ENUM)
 * because §11.5 requires that the model identifier be a free string so
 * Directors can switch from gpt-4o-mini to claude-sonnet-4-7-20260207
 * (or any future model) without a deployment.
 *
 * api_key_encrypted stores the result of Crypt::encryptString() — the
 * Filament page sets it via a write-only password input that re-encrypts
 * on every save and never echoes the plaintext back to the form.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE ai_settings (
                id                          UUID            PRIMARY KEY DEFAULT '00000000-0000-0000-0000-000000000001',
                global_enabled              BOOLEAN         NOT NULL DEFAULT FALSE,
                provider                    TEXT            NULL,
                model                       TEXT            NULL,
                endpoint                    TEXT            NULL,
                api_key_encrypted           TEXT            NULL,
                monthly_token_cap_default   BIGINT          NOT NULL DEFAULT 1000000,
                monthly_usd_cap_default     NUMERIC(10,2)   NOT NULL DEFAULT 100,
                updated_by                  UUID            NULL
                                            REFERENCES users (id) ON DELETE SET NULL,
                updated_at                  TIMESTAMPTZ     NULL,

                CONSTRAINT chk_ai_settings_singleton
                    CHECK (id = '00000000-0000-0000-0000-000000000001'::uuid),

                CONSTRAINT chk_ai_settings_provider
                    CHECK (provider IS NULL OR provider IN ('openai', 'anthropic'))
            )
        SQL);

        // Role grants — Filament reads as app_role under director scope.
        DB::statement('GRANT SELECT, INSERT, UPDATE ON ai_settings TO app_role');
        DB::statement('GRANT SELECT ON ai_settings TO worker_role');
        DB::statement('GRANT SELECT ON ai_settings TO report_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS ai_settings CASCADE');
    }
};
