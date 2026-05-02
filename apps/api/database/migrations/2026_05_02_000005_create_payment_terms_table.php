<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the payment_terms table.
 *
 * Standard terms (Contado 0d, 15d, 30d, 45d, 60d) are seeded via
 * PaymentTermSeeder — not here.
 *
 * days_to_due = 0 means "Contado" (immediate payment).
 *
 * A payment term cannot be hard-deleted if it is in use by any customer or
 * sale — only deactivated (is_active = false) so it is excluded from new
 * operations while historical records remain valid (§16.6).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE payment_terms (
                id           UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                name         TEXT        NOT NULL,
                days_to_due  INTEGER     NOT NULL,
                is_active    BOOLEAN     NOT NULL DEFAULT TRUE,
                created_at   TIMESTAMPTZ NULL,
                updated_at   TIMESTAMPTZ NULL
            )
        SQL);

        // Role grants
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON payment_terms TO app_role');
        DB::statement('GRANT SELECT ON payment_terms TO report_role');
        DB::statement('GRANT SELECT ON payment_terms TO worker_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS payment_terms CASCADE');
    }
};
