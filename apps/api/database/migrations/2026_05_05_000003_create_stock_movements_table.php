<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stock movements — immutable append-only ledger of all stock events.
 *
 * Movement types (§4.4 + §5.5):
 *   import              — arrival from supplier into central stock
 *   dispatch_to_dist    — central → distributor
 *   dispatch_to_seller  — central → seller (zona directa)
 *   redistribution      — distributor → seller (within zone)
 *   sale_reserve        — seller/distributor stock → reserved (Confirmada)
 *   sale_deliver        — reserved → consumed (Entregada)
 *   sale_cancel         — reserved → available (Cancelada)
 *   return_partial      — partial product return back to seller stock
 *
 * Polymorphic from/to entity (type + id) covers all actor types (central,
 * distributor, seller) without needing separate FK columns per actor type.
 *
 * Only created_at is stored — movements are never updated (append-only by design;
 * StockMovementObserver enforces CREATE-only audit logging).
 *
 * Indexes (per Phase 0 spec):
 *   - movement_type + created_at  — time-windowed queries per type
 *   - product_id + created_at     — product history queries
 *   - sale_id WHERE sale_id IS NOT NULL — Phase 5 sale→movements join
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            DO $$ BEGIN
                CREATE TYPE stock_movement_type AS ENUM (
                    'import',
                    'dispatch_to_dist',
                    'dispatch_to_seller',
                    'redistribution',
                    'sale_reserve',
                    'sale_deliver',
                    'sale_cancel',
                    'return_partial'
                );
            EXCEPTION
                WHEN duplicate_object THEN NULL;
            END $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE stock_movements (
                id                  UUID                    PRIMARY KEY DEFAULT gen_random_uuid(),
                movement_type       stock_movement_type     NOT NULL,
                product_id          UUID                    NOT NULL
                                    REFERENCES products (id) ON DELETE RESTRICT,
                from_entity_type    TEXT                    NULL,
                from_entity_id      UUID                    NULL,
                to_entity_type      TEXT                    NULL,
                to_entity_id        UUID                    NULL,
                quantity_boxes      INTEGER                 NOT NULL,
                quantity_units      INTEGER                 NOT NULL DEFAULT 0,
                lot_id              UUID                    NULL
                                    REFERENCES stock_lots (id) ON DELETE RESTRICT,
                reference_doc       TEXT                    NULL,
                sale_id             UUID                    NULL,
                created_by          UUID                    NOT NULL
                                    REFERENCES users (id) ON DELETE RESTRICT,
                created_at          TIMESTAMPTZ             NOT NULL DEFAULT NOW(),

                CONSTRAINT chk_stock_movements_quantity_positive
                    CHECK (quantity_boxes > 0 OR quantity_units > 0),

                CONSTRAINT chk_stock_movements_quantity_units_range
                    CHECK (quantity_units >= 0 AND quantity_units < 5)
            )
        SQL);

        DB::statement('CREATE INDEX idx_stock_movements_type_created ON stock_movements (movement_type, created_at DESC)');
        DB::statement('CREATE INDEX idx_stock_movements_product_created ON stock_movements (product_id, created_at DESC)');
        DB::statement('CREATE INDEX idx_stock_movements_sale_id ON stock_movements (sale_id) WHERE sale_id IS NOT NULL');
        DB::statement('CREATE INDEX idx_stock_movements_created_by ON stock_movements (created_by)');
        DB::statement('CREATE INDEX idx_stock_movements_lot_id ON stock_movements (lot_id) WHERE lot_id IS NOT NULL');

        DB::statement('GRANT SELECT, INSERT ON stock_movements TO app_role');
        DB::statement('GRANT SELECT ON stock_movements TO report_role');
        DB::statement('GRANT SELECT ON stock_movements TO worker_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS stock_movements CASCADE');
        DB::statement('DROP TYPE IF EXISTS stock_movement_type CASCADE');
    }
};
