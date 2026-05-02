<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Invoices table — §6 Xubio integration.
 *
 * DESIGN
 * ======
 * - external_ref UNIQUE — Stripe-style idempotency key. The IssueXubioInvoiceJob
 *   sets it to "sale-{sale_id}-{ulid}" and reuses it on retries so the AFIP
 *   timeout duplicate-invoice scenario is avoided.
 * - status enum lifecycle:
 *     pending           : row created, job not yet dispatched / running.
 *     reconciling       : POST timed out; ReconcileXubioInvoiceJob is searching.
 *     success           : Xubio confirmed; xubio_id + cae populated.
 *     failed_manual_review : reconcile could not locate the invoice in Xubio;
 *                       Director must investigate manually.
 * - voucher_type CHAR(1) CHECK A|B|C : determined by the billing entity's
 *   IVA condition at emission time.
 * - amount_ars NUMERIC(18,4) : AFIP requires ARS, so we always persist the
 *   ARS-equivalent at emission. exchange_rate_id pins the TC used.
 * - pdf_url stores the S3 key (or full URL) of the invoice PDF returned by Xubio.
 *
 * RLS is applied in 2026_05_07_000004_enable_billing_rls.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            DO $$ BEGIN
                CREATE TYPE invoice_status AS ENUM (
                    'pending',
                    'reconciling',
                    'success',
                    'failed_manual_review'
                );
            EXCEPTION
                WHEN duplicate_object THEN NULL;
            END $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE invoices (
                id                  UUID            PRIMARY KEY DEFAULT gen_random_uuid(),

                sale_id             UUID            NOT NULL
                                    REFERENCES sales (id) ON DELETE RESTRICT,

                billing_entity_id   UUID            NOT NULL
                                    REFERENCES customer_billing_entities (id) ON DELETE RESTRICT,

                -- Xubio linkage (NULL until Xubio confirms emission)
                xubio_id            TEXT            NULL,

                -- Idempotency key — deterministic per attempt; retries reuse the same value.
                external_ref        TEXT            NOT NULL,

                -- AFIP comprobante number (assigned by Xubio after CAE)
                invoice_number      TEXT            NULL,

                -- A | B | C per IVA condition
                voucher_type        CHAR(1)         NULL,

                -- AFIP CAE (Código de Autorización Electrónico)
                cae                 TEXT            NULL,

                -- Monetary fields (ARS only — AFIP requirement)
                amount_ars          NUMERIC(18,4)   NULL,
                exchange_rate_id    UUID            NULL
                                    REFERENCES exchange_rates (id) ON DELETE RESTRICT,

                pdf_url             TEXT            NULL,

                status              invoice_status  NOT NULL DEFAULT 'pending',

                issued_at           TIMESTAMPTZ     NULL,
                issued_by           UUID            NULL
                                    REFERENCES users (id) ON DELETE SET NULL,

                created_at          TIMESTAMPTZ     NULL,
                updated_at          TIMESTAMPTZ     NULL,

                CONSTRAINT uq_invoices_external_ref
                    UNIQUE (external_ref),

                CONSTRAINT chk_invoices_voucher_type
                    CHECK (voucher_type IS NULL OR voucher_type IN ('A', 'B', 'C')),

                CONSTRAINT chk_invoices_amount_non_negative
                    CHECK (amount_ars IS NULL OR amount_ars >= 0)
            )
        SQL);

        DB::statement('CREATE INDEX idx_invoices_sale_id ON invoices (sale_id)');
        DB::statement('CREATE INDEX idx_invoices_billing_entity_id ON invoices (billing_entity_id)');
        DB::statement('CREATE INDEX idx_invoices_status ON invoices (status)');
        DB::statement('CREATE INDEX idx_invoices_xubio_id ON invoices (xubio_id) WHERE xubio_id IS NOT NULL');
        DB::statement('CREATE INDEX idx_invoices_issued_at ON invoices (issued_at DESC)');

        DB::statement('GRANT SELECT, INSERT, UPDATE ON invoices TO app_role');
        DB::statement('GRANT SELECT ON invoices TO report_role');
        DB::statement('GRANT SELECT, INSERT, UPDATE ON invoices TO worker_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS invoices CASCADE');
        DB::statement('DROP TYPE IF EXISTS invoice_status CASCADE');
    }
};
