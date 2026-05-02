<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row Level Security for invoices & credit_notes (Phase 6).
 *
 * Mirrors the sales policy matrix:
 *   Director    → all rows
 *   Distributor → rows whose parent sale.zone_id is in their managed zones
 *   Seller      → rows whose parent sale.seller_id = caller
 *
 * Subselects use the sales table because invoices/credit_notes only carry
 * sale_id; replicating seller_id/zone_id columns here would introduce drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ================================================================
        // invoices
        // ================================================================
        DB::statement('ALTER TABLE invoices ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE invoices FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY invoices_director_all ON invoices
            AS PERMISSIVE FOR ALL TO app_role
            USING (current_setting('app.user_role', TRUE) = 'director')
            WITH CHECK (current_setting('app.user_role', TRUE) = 'director')
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY invoices_distributor_zone ON invoices
            AS PERMISSIVE FOR ALL TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND EXISTS (
                    SELECT 1 FROM sales s
                    WHERE s.id = invoices.sale_id
                    AND s.zone_id IN (
                        SELECT id FROM zones
                        WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
                    )
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND EXISTS (
                    SELECT 1 FROM sales s
                    WHERE s.id = invoices.sale_id
                    AND s.zone_id IN (
                        SELECT id FROM zones
                        WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
                    )
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY invoices_seller_own ON invoices
            AS PERMISSIVE FOR ALL TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'seller'
                AND EXISTS (
                    SELECT 1 FROM sales s
                    WHERE s.id = invoices.sale_id
                    AND s.seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'seller'
                AND EXISTS (
                    SELECT 1 FROM sales s
                    WHERE s.id = invoices.sale_id
                    AND s.seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY invoices_report_read ON invoices
            AS PERMISSIVE FOR SELECT TO report_role USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY invoices_worker_all ON invoices
            AS PERMISSIVE FOR ALL TO worker_role
            USING (TRUE) WITH CHECK (TRUE)
        SQL);

        // ================================================================
        // credit_notes
        // ================================================================
        DB::statement('ALTER TABLE credit_notes ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE credit_notes FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY credit_notes_director_all ON credit_notes
            AS PERMISSIVE FOR ALL TO app_role
            USING (current_setting('app.user_role', TRUE) = 'director')
            WITH CHECK (current_setting('app.user_role', TRUE) = 'director')
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY credit_notes_distributor_zone ON credit_notes
            AS PERMISSIVE FOR ALL TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND EXISTS (
                    SELECT 1 FROM sales s
                    WHERE s.id = credit_notes.sale_id
                    AND s.zone_id IN (
                        SELECT id FROM zones
                        WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
                    )
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND EXISTS (
                    SELECT 1 FROM sales s
                    WHERE s.id = credit_notes.sale_id
                    AND s.zone_id IN (
                        SELECT id FROM zones
                        WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
                    )
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY credit_notes_seller_own ON credit_notes
            AS PERMISSIVE FOR ALL TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'seller'
                AND EXISTS (
                    SELECT 1 FROM sales s
                    WHERE s.id = credit_notes.sale_id
                    AND s.seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'seller'
                AND EXISTS (
                    SELECT 1 FROM sales s
                    WHERE s.id = credit_notes.sale_id
                    AND s.seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY credit_notes_report_read ON credit_notes
            AS PERMISSIVE FOR SELECT TO report_role USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY credit_notes_worker_all ON credit_notes
            AS PERMISSIVE FOR ALL TO worker_role
            USING (TRUE) WITH CHECK (TRUE)
        SQL);
    }

    public function down(): void
    {
        foreach (['credit_notes_director_all', 'credit_notes_distributor_zone', 'credit_notes_seller_own', 'credit_notes_report_read', 'credit_notes_worker_all'] as $p) {
            DB::statement("DROP POLICY IF EXISTS {$p} ON credit_notes");
        }
        DB::statement('ALTER TABLE credit_notes DISABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE credit_notes NO FORCE ROW LEVEL SECURITY');

        foreach (['invoices_director_all', 'invoices_distributor_zone', 'invoices_seller_own', 'invoices_report_read', 'invoices_worker_all'] as $p) {
            DB::statement("DROP POLICY IF EXISTS {$p} ON invoices");
        }
        DB::statement('ALTER TABLE invoices DISABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE invoices NO FORCE ROW LEVEL SECURITY');
    }
};
