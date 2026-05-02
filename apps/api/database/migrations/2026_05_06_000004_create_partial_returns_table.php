<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Partial returns table — §5.8 devolucion parcial de producto.
 *
 * LIFECYCLE
 * =========
 * 1. Seller calls InitiatePartialReturnAction → status = 'pending_director_confirmation'
 * 2. Director calls ConfirmPartialReturnAction:
 *    a. If sale has an invoice → throws InvoiceNcRequiredException
 *       → status transitions to 'awaiting_credit_note' (Phase 6 will handle NC flow)
 *    b. If no invoice → stock returns to Seller, refund to customer credit balance
 *       → status = 'applied'
 * 3. After NC issued in Xubio (Phase 6) → status = 'nc_issued' → 'applied'
 * 4. Director can reject at step 2 → status = 'rejected'
 *
 * status enum values:
 *   - pending_director_confirmation : initiated by Seller, awaiting Director
 *   - awaiting_credit_note          : Director confirmed but invoice exists; NC pending
 *   - nc_issued                     : Xubio NC emitted; ready to apply refund
 *   - applied                       : stock reversed + refund credit posted
 *   - rejected                      : Director rejected the return
 *
 * refund_amount is computed by the Action (quantity × unit_price at sale time).
 * It is stored here so the credit balance posting (Phase 7) can read it
 * without re-joining to sale_items and computing proportions.
 *
 * confirmed_by and confirmed_at are NULL until the Director acts.
 *
 * RLS: Director can read/write all. Seller can read their own (sale.seller_id = caller).
 * Policies in migration 2026_05_06_000006.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ----------------------------------------------------------------
        // 1. partial_return_status ENUM
        // ----------------------------------------------------------------
        DB::statement(<<<'SQL'
            DO $$ BEGIN
                CREATE TYPE partial_return_status AS ENUM (
                    'pending_director_confirmation',
                    'awaiting_credit_note',
                    'nc_issued',
                    'applied',
                    'rejected'
                );
            EXCEPTION
                WHEN duplicate_object THEN NULL;
            END $$
        SQL);

        // ----------------------------------------------------------------
        // 2. partial_returns table
        // ----------------------------------------------------------------
        DB::statement(<<<'SQL'
            CREATE TABLE partial_returns (
                id                  UUID                    PRIMARY KEY DEFAULT gen_random_uuid(),

                sale_id             UUID                    NOT NULL
                                    REFERENCES sales (id) ON DELETE RESTRICT,

                sale_item_id        UUID                    NOT NULL
                                    REFERENCES sale_items (id) ON DELETE RESTRICT,

                -- Workflow actors
                initiated_by        UUID                    NOT NULL
                                    REFERENCES users (id) ON DELETE RESTRICT,
                confirmed_by        UUID                    NULL
                                    REFERENCES users (id) ON DELETE RESTRICT,

                status              partial_return_status   NOT NULL,

                -- Quantities being returned (subset of sale_item quantities)
                quantity_boxes      INTEGER                 NOT NULL DEFAULT 0,
                quantity_units      INTEGER                 NOT NULL DEFAULT 0,

                -- Refund value (computed at initiation time from sale_item unit price)
                refund_amount       NUMERIC(18,4)           NOT NULL,
                refund_currency     CHAR(3)                 NOT NULL,

                reason              TEXT                    NULL,

                -- Timestamps
                initiated_at        TIMESTAMPTZ             NOT NULL,
                confirmed_at        TIMESTAMPTZ             NULL,

                created_at          TIMESTAMPTZ             NULL,
                updated_at          TIMESTAMPTZ             NULL,

                CONSTRAINT chk_partial_returns_refund_currency
                    CHECK (refund_currency IN ('ARS', 'USD')),

                CONSTRAINT chk_partial_returns_quantity_non_negative
                    CHECK (quantity_boxes >= 0 AND quantity_units >= 0),

                CONSTRAINT chk_partial_returns_quantity_not_both_zero
                    CHECK (quantity_boxes > 0 OR quantity_units > 0),

                CONSTRAINT chk_partial_returns_units_max_four
                    CHECK (quantity_units BETWEEN 0 AND 4),

                CONSTRAINT chk_partial_returns_refund_positive
                    CHECK (refund_amount > 0),

                -- confirmed_at must be set when confirmed_by is set
                CONSTRAINT chk_partial_returns_confirmation_consistency
                    CHECK (
                        (confirmed_by IS NULL AND confirmed_at IS NULL)
                        OR (confirmed_by IS NOT NULL AND confirmed_at IS NOT NULL)
                    )
            )
        SQL);

        // ----------------------------------------------------------------
        // 3. Indexes
        // ----------------------------------------------------------------
        DB::statement('CREATE INDEX idx_partial_returns_sale_id ON partial_returns (sale_id)');
        DB::statement('CREATE INDEX idx_partial_returns_sale_item_id ON partial_returns (sale_item_id)');
        DB::statement('CREATE INDEX idx_partial_returns_status ON partial_returns (status)');
        DB::statement('CREATE INDEX idx_partial_returns_initiated_by ON partial_returns (initiated_by)');

        // ----------------------------------------------------------------
        // 4. Role grants
        // ----------------------------------------------------------------
        DB::statement('GRANT SELECT, INSERT, UPDATE ON partial_returns TO app_role');
        DB::statement('GRANT SELECT ON partial_returns TO report_role');
        DB::statement('GRANT SELECT ON partial_returns TO worker_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS partial_returns CASCADE');
        DB::statement('DROP TYPE IF EXISTS partial_return_status CASCADE');
    }
};
