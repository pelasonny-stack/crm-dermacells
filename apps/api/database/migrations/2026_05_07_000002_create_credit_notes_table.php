<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Credit notes table — §6.5 Notas de Crédito Xubio.
 *
 * Most NCs are partial-return-driven (sale_item_id + partial_return_id populated).
 * Manual NCs (Director-initiated cancellation NCs) leave those FKs NULL and
 * apply to the full invoice amount.
 *
 * status string (not enum, intentionally — fewer NC variants than invoices):
 *   pending | reconciling | success | failed_manual_review
 *
 * The schema mirrors invoices closely so the Xubio job pattern is reused.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE credit_notes (
                id                  UUID            PRIMARY KEY DEFAULT gen_random_uuid(),

                invoice_id          UUID            NOT NULL
                                    REFERENCES invoices (id) ON DELETE RESTRICT,

                sale_id             UUID            NOT NULL
                                    REFERENCES sales (id) ON DELETE RESTRICT,

                -- Optional: NULL when the NC covers the full invoice (cancellation flow);
                -- populated when emitted from a partial return (§5.8).
                sale_item_id        UUID            NULL
                                    REFERENCES sale_items (id) ON DELETE RESTRICT,

                partial_return_id   UUID            NULL
                                    REFERENCES partial_returns (id) ON DELETE RESTRICT,

                xubio_id            TEXT            NULL,
                external_ref        TEXT            NOT NULL,

                -- AFIP NC number (assigned after CAE)
                cn_number           TEXT            NULL,
                cae                 TEXT            NULL,

                amount_ars          NUMERIC(18,4)   NOT NULL,
                exchange_rate_id    UUID            NULL
                                    REFERENCES exchange_rates (id) ON DELETE RESTRICT,

                pdf_url             TEXT            NULL,

                status              TEXT            NOT NULL DEFAULT 'pending',

                issued_at           TIMESTAMPTZ     NULL,
                issued_by           UUID            NULL
                                    REFERENCES users (id) ON DELETE SET NULL,

                created_at          TIMESTAMPTZ     NULL,
                updated_at          TIMESTAMPTZ     NULL,

                CONSTRAINT uq_credit_notes_external_ref
                    UNIQUE (external_ref),

                CONSTRAINT chk_credit_notes_amount_positive
                    CHECK (amount_ars > 0),

                CONSTRAINT chk_credit_notes_status
                    CHECK (status IN ('pending', 'reconciling', 'success', 'failed_manual_review'))
            )
        SQL);

        DB::statement('CREATE INDEX idx_credit_notes_invoice_id ON credit_notes (invoice_id)');
        DB::statement('CREATE INDEX idx_credit_notes_sale_id ON credit_notes (sale_id)');
        DB::statement('CREATE INDEX idx_credit_notes_partial_return_id ON credit_notes (partial_return_id) WHERE partial_return_id IS NOT NULL');
        DB::statement('CREATE INDEX idx_credit_notes_status ON credit_notes (status)');
        DB::statement('CREATE INDEX idx_credit_notes_xubio_id ON credit_notes (xubio_id) WHERE xubio_id IS NOT NULL');

        DB::statement('GRANT SELECT, INSERT, UPDATE ON credit_notes TO app_role');
        DB::statement('GRANT SELECT ON credit_notes TO report_role');
        DB::statement('GRANT SELECT, INSERT, UPDATE ON credit_notes TO worker_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS credit_notes CASCADE');
    }
};
