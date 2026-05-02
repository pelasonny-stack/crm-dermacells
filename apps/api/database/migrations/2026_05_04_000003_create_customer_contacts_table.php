<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the customer_contacts table — contactos del cliente (§3.6).
 *
 * A customer may have multiple contacts. If a contact has a birthday, the
 * system sends a push notification to the assigned Seller at 8:00 AM ART on
 * that day (§3.6, §15).
 *
 * PARTIAL INDEX on birthday:
 *   The birthday-dispatch command runs daily and queries contacts where
 *   (birthday_month = EXTRACT(MONTH FROM now()) AND birthday_day = EXTRACT(DAY FROM now())).
 *   Since the majority of contacts will have NULL birthdays, a partial index
 *   WHERE birthday IS NOT NULL keeps this index small and fast.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE customer_contacts (
                id          UUID        NOT NULL PRIMARY KEY DEFAULT gen_random_uuid(),
                customer_id UUID        NOT NULL
                            REFERENCES customers (id) ON DELETE CASCADE,
                full_name   TEXT        NOT NULL,
                phone       TEXT        NOT NULL,
                email       TEXT        NOT NULL,
                role_label  TEXT        NOT NULL,
                birthday    DATE        NULL,
                created_at  TIMESTAMPTZ NULL,
                updated_at  TIMESTAMPTZ NULL
            )
        SQL);

        DB::statement('CREATE INDEX idx_contacts_customer_id ON customer_contacts (customer_id)');

        // Partial index for the birthday alert dispatch query (§3.6, §15)
        // Queried as: WHERE birthday IS NOT NULL AND EXTRACT(MONTH FROM birthday) = ? AND EXTRACT(DAY FROM birthday) = ?
        DB::statement(<<<'SQL'
            CREATE INDEX idx_contacts_birthday_not_null
                ON customer_contacts (birthday)
                WHERE birthday IS NOT NULL
        SQL);

        // Role grants
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON customer_contacts TO app_role');
        DB::statement('GRANT SELECT ON customer_contacts TO report_role');
        DB::statement('GRANT SELECT ON customer_contacts TO worker_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS customer_contacts CASCADE');
    }
};
