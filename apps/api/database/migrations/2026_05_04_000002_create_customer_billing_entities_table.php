<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the customer_billing_entities table — razones sociales (§3.5).
 *
 * A customer may have one or more billing entities (razones sociales). The
 * billing entity is chosen at invoice time, not when creating the sale.
 *
 * Only one billing entity per customer may be primary (is_primary = true).
 * This invariant is enforced at the application layer (not via a partial
 * unique index on is_primary = true) so that a non-primary-to-primary swap
 * can be done in a single atomic UPDATE without violating a constraint mid-
 * transaction.
 *
 * xubio_cliente_id is populated lazily by XubioClientResolver (Phase 6) on
 * first invoice emission. NULL means "not yet registered with Xubio".
 *
 * iva_condition values map to AFIP comprobante types:
 *   responsable_inscripto → Factura A
 *   monotributista        → Factura C
 *   consumidor_final      → Factura B
 *   exento                → Factura B (or C depending on context)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            DO $$ BEGIN
                CREATE TYPE iva_condition_enum AS ENUM (
                    'responsable_inscripto',
                    'monotributista',
                    'consumidor_final',
                    'exento'
                );
            EXCEPTION WHEN duplicate_object THEN NULL;
            END $$;
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE customer_billing_entities (
                id                UUID                NOT NULL PRIMARY KEY DEFAULT gen_random_uuid(),
                customer_id       UUID                NOT NULL
                                  REFERENCES customers (id) ON DELETE CASCADE,
                name              TEXT                NOT NULL,
                cuit              CHAR(11)            NOT NULL,
                iva_condition     iva_condition_enum  NOT NULL,
                is_primary        BOOLEAN             NOT NULL DEFAULT FALSE,
                xubio_cliente_id  TEXT                NULL,
                created_at        TIMESTAMPTZ         NULL,
                updated_at        TIMESTAMPTZ         NULL
            )
        SQL);

        DB::statement('CREATE INDEX idx_billing_entities_customer_id ON customer_billing_entities (customer_id)');

        // Fast lookup by Xubio ID (Phase 6 reconciliation)
        DB::statement('CREATE INDEX idx_billing_entities_xubio_id ON customer_billing_entities (xubio_cliente_id) WHERE xubio_cliente_id IS NOT NULL');

        // Role grants (inherit visibility from customers via application-layer join)
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON customer_billing_entities TO app_role');
        DB::statement('GRANT SELECT ON customer_billing_entities TO report_role');
        DB::statement('GRANT SELECT ON customer_billing_entities TO worker_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS customer_billing_entities CASCADE');
        DB::statement('DROP TYPE IF EXISTS iva_condition_enum');
    }
};
