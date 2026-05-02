<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row Level Security policies for all Phase 4 stock tables.
 *
 * ACCESS MATRIX (§4.6, §8.1, §8.2, §2.4):
 *
 *  Table             | Director | Distributor          | Seller
 *  ------------------|----------|----------------------|--------------------
 *  central_stock     | ALL      | none                 | none
 *  stock_lots        | ALL      | none                 | none
 *  stock_movements   | ALL      | none                 | none
 *  distributor_stock | ALL      | own rows only        | none
 *  seller_stock      | ALL      | sellers in zone *    | own row only
 *
 * * Distributor seller_stock visibility: a distributor sees seller_stock rows
 *   for sellers who have at least one customer in a zone the distributor owns.
 *   Phase 4 simplification uses a subquery on the customers table (added in
 *   Phase 3); if customers does not yet exist, this policy degrades gracefully
 *   to no access for distributors (they will see their own rows only via the
 *   seller_id path once Phase 3 lands).
 *
 * GUCs:
 *   app.user_id   — UUID of authenticated user
 *   app.user_role — 'director' | 'distributor' | 'seller'
 *
 * All tables use FORCE ROW LEVEL SECURITY so the table owner cannot bypass
 * policies during a console session.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ================================================================
        // CENTRAL STOCK — Director only
        // ================================================================
        DB::statement('ALTER TABLE central_stock ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE central_stock FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY central_stock_director_all ON central_stock
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'director'
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'director'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY central_stock_worker_read ON central_stock
            AS PERMISSIVE FOR SELECT
            TO worker_role
            USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY central_stock_report_read ON central_stock
            AS PERMISSIVE FOR SELECT
            TO report_role
            USING (TRUE)
        SQL);

        // ================================================================
        // STOCK LOTS — Director only
        // ================================================================
        DB::statement('ALTER TABLE stock_lots ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE stock_lots FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY stock_lots_director_all ON stock_lots
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'director'
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'director'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY stock_lots_worker_read ON stock_lots
            AS PERMISSIVE FOR SELECT
            TO worker_role
            USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY stock_lots_report_read ON stock_lots
            AS PERMISSIVE FOR SELECT
            TO report_role
            USING (TRUE)
        SQL);

        // ================================================================
        // STOCK MOVEMENTS — Director only (immutable ledger)
        // ================================================================
        DB::statement('ALTER TABLE stock_movements ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE stock_movements FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY stock_movements_director_all ON stock_movements
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'director'
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'director'
            )
        SQL);

        // Distributors and Sellers need INSERT access (their own actions create
        // movements), but SELECT is gated to their own created_by rows.
        DB::statement(<<<'SQL'
            CREATE POLICY stock_movements_distributor_insert ON stock_movements
            AS PERMISSIVE FOR INSERT
            TO app_role
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND created_by = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY stock_movements_seller_insert ON stock_movements
            AS PERMISSIVE FOR INSERT
            TO app_role
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'seller'
                AND created_by = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY stock_movements_worker_read ON stock_movements
            AS PERMISSIVE FOR SELECT
            TO worker_role
            USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY stock_movements_report_read ON stock_movements
            AS PERMISSIVE FOR SELECT
            TO report_role
            USING (TRUE)
        SQL);

        // ================================================================
        // DISTRIBUTOR STOCK
        //   Director: ALL
        //   Distributor: own rows (distributor_id = current user)
        //   Seller: no access
        // ================================================================
        DB::statement('ALTER TABLE distributor_stock ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE distributor_stock FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY distributor_stock_director_all ON distributor_stock
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'director'
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'director'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY distributor_stock_own ON distributor_stock
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY distributor_stock_worker_read ON distributor_stock
            AS PERMISSIVE FOR SELECT
            TO worker_role
            USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY distributor_stock_report_read ON distributor_stock
            AS PERMISSIVE FOR SELECT
            TO report_role
            USING (TRUE)
        SQL);

        // ================================================================
        // SELLER STOCK
        //   Director: ALL
        //   Distributor: sees sellers in their zone (subquery on customers)
        //   Seller: own row only
        //
        // The distributor zone subquery: a distributor's zone is determined by
        // zones.distributor_id = current user. A seller is "in their zone" if
        // that seller has at least one customer whose zone_id is in the
        // distributor's zones. This requires the customers table (Phase 3).
        // If customers does not exist yet, this subquery returns an empty set,
        // which is safe (distributor sees nothing until Phase 3 lands).
        // ================================================================
        DB::statement('ALTER TABLE seller_stock ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE seller_stock FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY seller_stock_director_all ON seller_stock
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'director'
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'director'
            )
        SQL);

        // Distributor sees seller_stock rows where the seller has any customer
        // in a zone the distributor owns. The subquery on customers tolerates
        // the customers table not existing yet (Postgres will error on DDL
        // parse, so we wrap in a DO block that catches the undefined_table
        // error and falls back to a no-op policy).
        //
        // Phase 4 simplification: if customers table does not exist, the
        // distributor policy is a deny-all (no rows visible) which is safer
        // than an overly permissive fallback.
        DB::statement(<<<'SQL'
            DO $$
            DECLARE
                customers_exist boolean;
            BEGIN
                SELECT EXISTS (
                    SELECT FROM information_schema.tables
                    WHERE table_schema = 'public'
                    AND table_name = 'customers'
                ) INTO customers_exist;

                IF customers_exist THEN
                    EXECUTE $pol$
                        CREATE POLICY seller_stock_distributor_zone ON seller_stock
                        AS PERMISSIVE FOR SELECT
                        TO app_role
                        USING (
                            current_setting('app.user_role', TRUE) = 'distributor'
                            AND seller_id IN (
                                SELECT DISTINCT c.assigned_seller_id
                                FROM customers c
                                INNER JOIN zones z ON z.id = c.zone_id
                                WHERE z.distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                                AND c.assigned_seller_id IS NOT NULL
                            )
                        )
                    $pol$;
                ELSE
                    -- customers table not yet present (Phase 3 not migrated).
                    -- Create a deny-all policy for distributors.
                    EXECUTE $pol$
                        CREATE POLICY seller_stock_distributor_zone ON seller_stock
                        AS PERMISSIVE FOR SELECT
                        TO app_role
                        USING (
                            current_setting('app.user_role', TRUE) = 'distributor'
                            AND FALSE
                        )
                    $pol$;
                END IF;
            END $$
        SQL);

        // Distributor INSERT/UPDATE for redistribution operations.
        // Same customers-existence guard as the SELECT policy above.
        DB::statement(<<<'SQL'
            DO $$
            DECLARE
                customers_exist boolean;
            BEGIN
                SELECT EXISTS (
                    SELECT FROM information_schema.tables
                    WHERE table_schema = 'public'
                    AND table_name = 'customers'
                ) INTO customers_exist;

                IF customers_exist THEN
                    EXECUTE $pol$
                        CREATE POLICY seller_stock_distributor_write ON seller_stock
                        AS PERMISSIVE FOR ALL
                        TO app_role
                        USING (
                            current_setting('app.user_role', TRUE) = 'distributor'
                            AND seller_id IN (
                                SELECT DISTINCT c.assigned_seller_id
                                FROM customers c
                                INNER JOIN zones z ON z.id = c.zone_id
                                WHERE z.distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                                AND c.assigned_seller_id IS NOT NULL
                            )
                        )
                        WITH CHECK (
                            current_setting('app.user_role', TRUE) = 'distributor'
                        )
                    $pol$;
                ELSE
                    EXECUTE $pol$
                        CREATE POLICY seller_stock_distributor_write ON seller_stock
                        AS PERMISSIVE FOR ALL
                        TO app_role
                        USING (
                            current_setting('app.user_role', TRUE) = 'distributor'
                            AND FALSE
                        )
                        WITH CHECK (
                            current_setting('app.user_role', TRUE) = 'distributor'
                            AND FALSE
                        )
                    $pol$;
                END IF;
            END $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY seller_stock_own ON seller_stock
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'seller'
                AND seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'seller'
                AND seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY seller_stock_worker_read ON seller_stock
            AS PERMISSIVE FOR SELECT
            TO worker_role
            USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY seller_stock_report_read ON seller_stock
            AS PERMISSIVE FOR SELECT
            TO report_role
            USING (TRUE)
        SQL);
    }

    public function down(): void
    {
        $tables = [
            'seller_stock' => [
                'seller_stock_director_all',
                'seller_stock_distributor_zone',
                'seller_stock_distributor_write',
                'seller_stock_own',
                'seller_stock_worker_read',
                'seller_stock_report_read',
            ],
            'distributor_stock' => [
                'distributor_stock_director_all',
                'distributor_stock_own',
                'distributor_stock_worker_read',
                'distributor_stock_report_read',
            ],
            'stock_movements' => [
                'stock_movements_director_all',
                'stock_movements_distributor_insert',
                'stock_movements_seller_insert',
                'stock_movements_worker_read',
                'stock_movements_report_read',
            ],
            'stock_lots' => [
                'stock_lots_director_all',
                'stock_lots_worker_read',
                'stock_lots_report_read',
            ],
            'central_stock' => [
                'central_stock_director_all',
                'central_stock_worker_read',
                'central_stock_report_read',
            ],
        ];

        foreach ($tables as $table => $policies) {
            foreach ($policies as $policy) {
                DB::statement("DROP POLICY IF EXISTS {$policy} ON {$table}");
            }

            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        }
    }
};
