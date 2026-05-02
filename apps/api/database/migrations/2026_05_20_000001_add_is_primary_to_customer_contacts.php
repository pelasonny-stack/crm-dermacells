<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds `is_primary` flag on customer_contacts mirroring the pattern
 * used by customer_billing_entities. A customer can have many contacts
 * (owner, medical director, administrative) but at most one principal.
 *
 * Partial UNIQUE index enforces the one-principal-per-customer rule
 * at the DB level.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE customer_contacts ADD COLUMN is_primary BOOLEAN NOT NULL DEFAULT FALSE');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX uq_customer_contacts_one_primary
            ON customer_contacts (customer_id)
            WHERE is_primary = TRUE
        SQL);

        // Backfill: for each customer with at least one contact, mark the
        // earliest-created contact as the principal so existing data has
        // a canonical primary.
        DB::statement(<<<'SQL'
            UPDATE customer_contacts cc
            SET is_primary = TRUE
            WHERE cc.id IN (
                SELECT DISTINCT ON (customer_id) id
                FROM customer_contacts
                ORDER BY customer_id, created_at ASC NULLS LAST, id ASC
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_customer_contacts_one_primary');
        DB::statement('ALTER TABLE customer_contacts DROP COLUMN IF EXISTS is_primary');
    }
};
