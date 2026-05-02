<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13 — ai_user_overrides table (§11.1).
 *
 * Per-user override of the global AI module activation and token / USD
 * monthly caps. When a row exists, its `enabled` value short-circuits
 * the global switch for that user (false here = denied even if global is on).
 *
 * monthly_token_cap and monthly_usd_cap are NULLABLE — NULL means "use the
 * default from ai_settings.monthly_*_cap_default". Resolution happens in
 * EnforceAiTokenCap middleware via COALESCE.
 *
 * Directors are exempt from per-user override checks (§11.1: "Los Directores
 * siempre tienen acceso al asistente cuando el módulo está activo globalmente").
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE ai_user_overrides (
                user_id            UUID            PRIMARY KEY
                                   REFERENCES users (id) ON DELETE CASCADE,
                enabled            BOOLEAN         NOT NULL DEFAULT TRUE,
                monthly_token_cap  BIGINT          NULL,
                monthly_usd_cap    NUMERIC(10,2)   NULL,
                updated_at         TIMESTAMPTZ     NULL
            )
        SQL);

        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON ai_user_overrides TO app_role');
        DB::statement('GRANT SELECT ON ai_user_overrides TO worker_role');
        DB::statement('GRANT SELECT ON ai_user_overrides TO report_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS ai_user_overrides CASCADE');
    }
};
