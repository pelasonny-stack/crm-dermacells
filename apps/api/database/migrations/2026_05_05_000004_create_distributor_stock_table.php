<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Distributor stock table — per-distributor per-product inventory summary.
 *
 * Unlike central_stock, `available` is not a generated column here because
 * Phase 5 needs to atomically decrement it with reserved amounts without
 * recomputing across multiple counters at constraint-check time.
 *
 * The application layer (RedistributeFromDistributorAction + sale reserve/commit
 * actions) keeps total_received, total_redistributed, reserved, and available
 * consistent inside DB transactions.
 *
 * UNIQUE (distributor_id, product_id) enforces one row per pair — upsert-safe
 * via INSERT ... ON CONFLICT DO UPDATE.
 *
 * RLS policies in migration 000006.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE distributor_stock (
                id                      UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                distributor_id          UUID        NOT NULL
                                        REFERENCES users (id) ON DELETE RESTRICT,
                product_id              UUID        NOT NULL
                                        REFERENCES products (id) ON DELETE RESTRICT,
                total_received          INTEGER     NOT NULL DEFAULT 0,
                total_redistributed     INTEGER     NOT NULL DEFAULT 0,
                reserved                INTEGER     NOT NULL DEFAULT 0,
                available               INTEGER     NOT NULL DEFAULT 0,
                minimum_stock           INTEGER     NOT NULL DEFAULT 0,
                created_at              TIMESTAMPTZ NULL,
                updated_at              TIMESTAMPTZ NULL,

                CONSTRAINT uq_distributor_stock_distributor_product
                    UNIQUE (distributor_id, product_id),

                CONSTRAINT chk_distributor_stock_received_non_negative
                    CHECK (total_received >= 0),

                CONSTRAINT chk_distributor_stock_redistributed_non_negative
                    CHECK (total_redistributed >= 0),

                CONSTRAINT chk_distributor_stock_reserved_non_negative
                    CHECK (reserved >= 0),

                CONSTRAINT chk_distributor_stock_available_non_negative
                    CHECK (available >= 0),

                CONSTRAINT chk_distributor_stock_minimum_non_negative
                    CHECK (minimum_stock >= 0)
            )
        SQL);

        DB::statement('CREATE INDEX idx_distributor_stock_distributor ON distributor_stock (distributor_id)');
        DB::statement('CREATE INDEX idx_distributor_stock_product ON distributor_stock (product_id)');
        DB::statement('CREATE INDEX idx_distributor_stock_below_min ON distributor_stock (distributor_id, product_id) WHERE available < minimum_stock AND minimum_stock > 0');

        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON distributor_stock TO app_role');
        DB::statement('GRANT SELECT ON distributor_stock TO report_role');
        DB::statement('GRANT SELECT ON distributor_stock TO worker_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS distributor_stock CASCADE');
    }
};
