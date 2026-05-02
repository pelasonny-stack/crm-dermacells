<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seller stock table — per-seller per-product inventory (§8.2).
 *
 * boxes:          full boxes held by the seller.
 * loose_units:    partial box units (0-4). 5 units = 1 full box (constraint enforced).
 * reserved_boxes: boxes locked by confirmed-but-not-delivered sales.
 * reserved_units: units locked by confirmed-but-not-delivered sales.
 * minimum_stock:  per-seller per-product threshold for low-stock alerts (§8.3).
 *
 * The CHECK constraint on loose_units enforces the 0–4 invariant — application
 * code must consolidate into boxes before storing 5+ units.
 *
 * UNIQUE (seller_id, product_id) — one row per pair.
 *
 * RLS policies in migration 000006.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE seller_stock (
                id              UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                seller_id       UUID        NOT NULL
                                REFERENCES users (id) ON DELETE RESTRICT,
                product_id      UUID        NOT NULL
                                REFERENCES products (id) ON DELETE RESTRICT,
                boxes           INTEGER     NOT NULL DEFAULT 0,
                loose_units     INTEGER     NOT NULL DEFAULT 0,
                reserved_boxes  INTEGER     NOT NULL DEFAULT 0,
                reserved_units  INTEGER     NOT NULL DEFAULT 0,
                minimum_stock   INTEGER     NOT NULL DEFAULT 0,
                created_at      TIMESTAMPTZ NULL,
                updated_at      TIMESTAMPTZ NULL,

                CONSTRAINT uq_seller_stock_seller_product
                    UNIQUE (seller_id, product_id),

                CONSTRAINT chk_seller_stock_loose_units_range
                    CHECK (loose_units >= 0 AND loose_units < 5),

                CONSTRAINT chk_seller_stock_boxes_non_negative
                    CHECK (boxes >= 0),

                CONSTRAINT chk_seller_stock_reserved_boxes_non_negative
                    CHECK (reserved_boxes >= 0),

                CONSTRAINT chk_seller_stock_reserved_units_non_negative
                    CHECK (reserved_units >= 0),

                CONSTRAINT chk_seller_stock_minimum_non_negative
                    CHECK (minimum_stock >= 0)
            )
        SQL);

        DB::statement('CREATE INDEX idx_seller_stock_seller ON seller_stock (seller_id)');
        DB::statement('CREATE INDEX idx_seller_stock_product ON seller_stock (product_id)');
        DB::statement('CREATE INDEX idx_seller_stock_below_min ON seller_stock (seller_id, product_id) WHERE boxes < minimum_stock AND minimum_stock > 0');

        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON seller_stock TO app_role');
        DB::statement('GRANT SELECT ON seller_stock TO report_role');
        DB::statement('GRANT SELECT ON seller_stock TO worker_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS seller_stock CASCADE');
    }
};
