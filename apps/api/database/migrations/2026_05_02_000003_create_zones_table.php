<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the zones table.
 *
 * A zone with distributor_id = NULL is a "zona directa" — stock is dispatched
 * by a Director directly to Vendors in that zone (§2.3).
 *
 * GUCs read by RLS policies on this table:
 *   - app.user_id   (uuid)
 *   - app.user_role (director | distributor | seller)
 *
 * RLS policies are created in migration 2026_05_02_000006_enable_rls_policies.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE zones (
                id               UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                name             TEXT        NOT NULL,
                distributor_id   UUID        NULL
                                 REFERENCES users (id) ON DELETE SET NULL,
                is_active        BOOLEAN     NOT NULL DEFAULT TRUE,
                created_at       TIMESTAMPTZ NULL,
                updated_at       TIMESTAMPTZ NULL
            )
        SQL);

        DB::statement('CREATE INDEX idx_zones_distributor_id ON zones (distributor_id)');

        // Role grants
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON zones TO app_role');
        DB::statement('GRANT SELECT ON zones TO report_role');
        DB::statement('GRANT SELECT ON zones TO worker_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS zones CASCADE');
    }
};
