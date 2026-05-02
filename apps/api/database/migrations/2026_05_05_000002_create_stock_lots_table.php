<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stock lots table — importation batches with lot number and optional expiry.
 *
 * Each call to RegisterImportAction creates one row here plus a matching
 * StockMovement of type 'import'. The lot record is the source of truth for
 * traceability back to supplier and expiry, which LotExpiryAlertJob scans.
 *
 * Indexes:
 *   - (product_id)          — most common query pattern: lots per product.
 *   - (expiry_date) WHERE expiry_date IS NOT NULL — partial index so the
 *     expiry alert job does fast range scans without touching NULL rows.
 *
 * RLS: Director only. Policies in migration 000006.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE stock_lots (
                id              UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                product_id      UUID        NOT NULL
                                REFERENCES products (id) ON DELETE RESTRICT,
                lot_number      TEXT        NOT NULL,
                import_date     DATE        NOT NULL,
                supplier        TEXT        NULL,
                quantity_boxes  INTEGER     NOT NULL,
                expiry_date     DATE        NULL,
                registered_by   UUID        NOT NULL
                                REFERENCES users (id) ON DELETE RESTRICT,
                created_at      TIMESTAMPTZ NULL,
                updated_at      TIMESTAMPTZ NULL,

                CONSTRAINT chk_stock_lots_quantity_positive
                    CHECK (quantity_boxes > 0),

                CONSTRAINT chk_stock_lots_expiry_after_import
                    CHECK (expiry_date IS NULL OR expiry_date > import_date)
            )
        SQL);

        DB::statement('CREATE INDEX idx_stock_lots_product_id ON stock_lots (product_id)');

        // Partial index: only lots that actually have an expiry date are scanned
        // by LotExpiryAlertJob. Without WHERE, NULL rows waste index space.
        DB::statement('CREATE INDEX idx_stock_lots_expiry_date ON stock_lots (expiry_date) WHERE expiry_date IS NOT NULL');

        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON stock_lots TO app_role');
        DB::statement('GRANT SELECT ON stock_lots TO report_role');
        DB::statement('GRANT SELECT ON stock_lots TO worker_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS stock_lots CASCADE');
    }
};
