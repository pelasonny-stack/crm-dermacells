<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row Level Security policies for Phase 5 tables.
 *
 * POLICY MATRIX
 * =============
 *
 * sales:
 *   Director  → sees ALL rows (no restriction)
 *   Distributor → sees rows WHERE zone_id IN (zones where they are the distributor)
 *   Seller    → sees rows WHERE seller_id = current app.user_id
 *
 * sale_items:
 *   Inherits indirectly via FK JOIN to sales. Defense-in-depth: same pattern,
 *   scoped via a sub-select on the sales table rather than duplicating the joins.
 *
 * sales_status_history:
 *   Same scope as sales (via sale_id FK sub-select).
 *
 * partial_returns:
 *   Director  → all
 *   Seller    → rows where the parent sale.seller_id = current user
 *   Distributor → rows where the parent sale.zone_id is their zone
 *
 * idempotency_keys:
 *   No RLS — always filtered by application layer on user_id.
 *   (The UNIQUE constraint on (user_id, key) is the security boundary.)
 *
 * GUCs used (set by SetPostgresRlsContext middleware per-transaction):
 *   app.user_id   UUID
 *   app.user_role 'director' | 'distributor' | 'seller'
 *
 * For Distributor zone membership we need to look up zones.distributor_id.
 * The zones table has a `distributor_id UUID REFERENCES users(id)` column
 * established in Phase 1 migration 2026_05_02_000003_create_zones_table.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ================================================================
        // sales
        // ================================================================
        DB::statement('ALTER TABLE sales ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE sales FORCE ROW LEVEL SECURITY');

        // Director: full access
        DB::statement(<<<'SQL'
            CREATE POLICY sales_director_all ON sales
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'director'
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'director'
            )
        SQL);

        // Distributor: sees sales whose zone_id belongs to their managed zone(s)
        DB::statement(<<<'SQL'
            CREATE POLICY sales_distributor_zone ON sales
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND zone_id IN (
                    SELECT id FROM zones
                    WHERE distributor_id = current_setting('app.user_id', TRUE)::uuid
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND zone_id IN (
                    SELECT id FROM zones
                    WHERE distributor_id = current_setting('app.user_id', TRUE)::uuid
                )
            )
        SQL);

        // Seller: sees only their own sales
        DB::statement(<<<'SQL'
            CREATE POLICY sales_seller_own ON sales
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'seller'
                AND seller_id = current_setting('app.user_id', TRUE)::uuid
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'seller'
                AND seller_id = current_setting('app.user_id', TRUE)::uuid
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY sales_report_read ON sales
            AS PERMISSIVE FOR SELECT
            TO report_role
            USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY sales_worker_read ON sales
            AS PERMISSIVE FOR SELECT
            TO worker_role
            USING (TRUE)
        SQL);

        // ================================================================
        // sale_items
        // ================================================================
        DB::statement('ALTER TABLE sale_items ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE sale_items FORCE ROW LEVEL SECURITY');

        // Director: all items
        DB::statement(<<<'SQL'
            CREATE POLICY sale_items_director_all ON sale_items
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (current_setting('app.user_role', TRUE) = 'director')
            WITH CHECK (current_setting('app.user_role', TRUE) = 'director')
        SQL);

        // Distributor: items belonging to their zone's sales
        DB::statement(<<<'SQL'
            CREATE POLICY sale_items_distributor_zone ON sale_items
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND sale_id IN (
                    SELECT id FROM sales
                    WHERE zone_id IN (
                        SELECT id FROM zones
                        WHERE distributor_id = current_setting('app.user_id', TRUE)::uuid
                    )
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND sale_id IN (
                    SELECT id FROM sales
                    WHERE zone_id IN (
                        SELECT id FROM zones
                        WHERE distributor_id = current_setting('app.user_id', TRUE)::uuid
                    )
                )
            )
        SQL);

        // Seller: items belonging to their own sales
        DB::statement(<<<'SQL'
            CREATE POLICY sale_items_seller_own ON sale_items
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'seller'
                AND sale_id IN (
                    SELECT id FROM sales
                    WHERE seller_id = current_setting('app.user_id', TRUE)::uuid
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'seller'
                AND sale_id IN (
                    SELECT id FROM sales
                    WHERE seller_id = current_setting('app.user_id', TRUE)::uuid
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY sale_items_report_read ON sale_items
            AS PERMISSIVE FOR SELECT TO report_role USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY sale_items_worker_read ON sale_items
            AS PERMISSIVE FOR SELECT TO worker_role USING (TRUE)
        SQL);

        // ================================================================
        // sales_status_history
        // ================================================================
        DB::statement('ALTER TABLE sales_status_history ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE sales_status_history FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY ssh_director_all ON sales_status_history
            AS PERMISSIVE FOR ALL TO app_role
            USING (current_setting('app.user_role', TRUE) = 'director')
            WITH CHECK (current_setting('app.user_role', TRUE) = 'director')
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY ssh_distributor_zone ON sales_status_history
            AS PERMISSIVE FOR ALL TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND sale_id IN (
                    SELECT id FROM sales WHERE zone_id IN (
                        SELECT id FROM zones
                        WHERE distributor_id = current_setting('app.user_id', TRUE)::uuid
                    )
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND sale_id IN (
                    SELECT id FROM sales WHERE zone_id IN (
                        SELECT id FROM zones
                        WHERE distributor_id = current_setting('app.user_id', TRUE)::uuid
                    )
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY ssh_seller_own ON sales_status_history
            AS PERMISSIVE FOR ALL TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'seller'
                AND sale_id IN (
                    SELECT id FROM sales
                    WHERE seller_id = current_setting('app.user_id', TRUE)::uuid
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'seller'
                AND sale_id IN (
                    SELECT id FROM sales
                    WHERE seller_id = current_setting('app.user_id', TRUE)::uuid
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY ssh_report_read ON sales_status_history
            AS PERMISSIVE FOR SELECT TO report_role USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY ssh_worker_read ON sales_status_history
            AS PERMISSIVE FOR SELECT TO worker_role USING (TRUE)
        SQL);

        // ================================================================
        // partial_returns
        // ================================================================
        DB::statement('ALTER TABLE partial_returns ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE partial_returns FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY pr_director_all ON partial_returns
            AS PERMISSIVE FOR ALL TO app_role
            USING (current_setting('app.user_role', TRUE) = 'director')
            WITH CHECK (current_setting('app.user_role', TRUE) = 'director')
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY pr_distributor_zone ON partial_returns
            AS PERMISSIVE FOR SELECT TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND sale_id IN (
                    SELECT id FROM sales WHERE zone_id IN (
                        SELECT id FROM zones
                        WHERE distributor_id = current_setting('app.user_id', TRUE)::uuid
                    )
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY pr_seller_own ON partial_returns
            AS PERMISSIVE FOR ALL TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'seller'
                AND (
                    initiated_by = current_setting('app.user_id', TRUE)::uuid
                    OR sale_id IN (
                        SELECT id FROM sales
                        WHERE seller_id = current_setting('app.user_id', TRUE)::uuid
                    )
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'seller'
                AND sale_id IN (
                    SELECT id FROM sales
                    WHERE seller_id = current_setting('app.user_id', TRUE)::uuid
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY pr_report_read ON partial_returns
            AS PERMISSIVE FOR SELECT TO report_role USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY pr_worker_read ON partial_returns
            AS PERMISSIVE FOR SELECT TO worker_role USING (TRUE)
        SQL);
    }

    public function down(): void
    {
        // partial_returns
        foreach (['pr_director_all', 'pr_distributor_zone', 'pr_seller_own', 'pr_report_read', 'pr_worker_read'] as $p) {
            DB::statement("DROP POLICY IF EXISTS {$p} ON partial_returns");
        }
        DB::statement('ALTER TABLE partial_returns DISABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE partial_returns NO FORCE ROW LEVEL SECURITY');

        // sales_status_history
        foreach (['ssh_director_all', 'ssh_distributor_zone', 'ssh_seller_own', 'ssh_report_read', 'ssh_worker_read'] as $p) {
            DB::statement("DROP POLICY IF EXISTS {$p} ON sales_status_history");
        }
        DB::statement('ALTER TABLE sales_status_history DISABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE sales_status_history NO FORCE ROW LEVEL SECURITY');

        // sale_items
        foreach (['sale_items_director_all', 'sale_items_distributor_zone', 'sale_items_seller_own', 'sale_items_report_read', 'sale_items_worker_read'] as $p) {
            DB::statement("DROP POLICY IF EXISTS {$p} ON sale_items");
        }
        DB::statement('ALTER TABLE sale_items DISABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE sale_items NO FORCE ROW LEVEL SECURITY');

        // sales
        foreach (['sales_director_all', 'sales_distributor_zone', 'sales_seller_own', 'sales_report_read', 'sales_worker_read'] as $p) {
            DB::statement("DROP POLICY IF EXISTS {$p} ON sales");
        }
        DB::statement('ALTER TABLE sales DISABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE sales NO FORCE ROW LEVEL SECURITY');
    }
};
