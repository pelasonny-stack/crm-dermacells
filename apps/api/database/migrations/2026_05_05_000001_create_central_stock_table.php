<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Central stock table (bodega Dermacells) — §4.
 *
 * One row per product. The `available` column is a generated column so reads are
 * always consistent without application-layer arithmetic.
 *
 * total_imported and total_dispatched are cumulative — we never decrement them
 * directly; the generated column derived from them IS the authoritative available
 * figure. Stock movements track the individual events.
 *
 * RLS: Director only (read + write). Distributors and Sellers have no access
 * to central stock (§4.6). Policies are created in migration 000006.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE central_stock (
                id                  UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                product_id          UUID        NOT NULL
                                    REFERENCES products (id) ON DELETE RESTRICT,
                total_imported      INTEGER     NOT NULL DEFAULT 0,
                total_dispatched    INTEGER     NOT NULL DEFAULT 0,
                available           INTEGER     GENERATED ALWAYS AS (total_imported - total_dispatched) STORED,
                minimum_stock       INTEGER     NOT NULL DEFAULT 0,
                created_at          TIMESTAMPTZ NULL,
                updated_at          TIMESTAMPTZ NULL,

                CONSTRAINT uq_central_stock_product
                    UNIQUE (product_id),

                CONSTRAINT chk_central_stock_imported_non_negative
                    CHECK (total_imported >= 0),

                CONSTRAINT chk_central_stock_dispatched_non_negative
                    CHECK (total_dispatched >= 0),

                CONSTRAINT chk_central_stock_dispatched_lte_imported
                    CHECK (total_dispatched <= total_imported),

                CONSTRAINT chk_central_stock_minimum_non_negative
                    CHECK (minimum_stock >= 0)
            )
        SQL);

        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON central_stock TO app_role');
        DB::statement('GRANT SELECT ON central_stock TO report_role');
        DB::statement('GRANT SELECT ON central_stock TO worker_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS central_stock CASCADE');
    }
};
