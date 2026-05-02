<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 10 — RLS policies for purchase_evolution_metrics and alerts.
 *
 * purchase_evolution_metrics visibility:
 *   Director  — sees all rows (no restriction)
 *   Distributor — sees rows for customers in their zone
 *   Seller    — sees rows for customers where assigned_seller_id = user
 *
 * alerts visibility:
 *   Director  — sees all alerts
 *   Distributor/Seller — see only their own alerts (target_user_id = user)
 *
 * Both tables inherit the same GUC pattern established in Phase 1:
 *   app.user_id   (UUID text)
 *   app.user_role (role text: 'director' | 'distributor' | 'seller')
 *
 * The distributor zone join goes through customers.zone_id matched against
 * the zones table where distributor_id = current_user_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ----------------------------------------------------------------
        // purchase_evolution_metrics RLS
        // ----------------------------------------------------------------
        DB::statement('ALTER TABLE purchase_evolution_metrics ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE purchase_evolution_metrics FORCE ROW LEVEL SECURITY');

        // Director: full access
        DB::statement(<<<'SQL'
            CREATE POLICY pem_director_all ON purchase_evolution_metrics
                FOR ALL
                USING (current_setting('app.user_role', TRUE) = 'director')
        SQL);

        // Distributor: customers in their zone
        DB::statement(<<<'SQL'
            CREATE POLICY pem_distributor_zone ON purchase_evolution_metrics
                FOR SELECT
                USING (
                    current_setting('app.user_role', TRUE) = 'distributor'
                    AND customer_id IN (
                        SELECT c.id
                        FROM customers c
                        JOIN zones z ON z.id = c.zone_id
                        WHERE z.distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                    )
                )
        SQL);

        // Seller: own customers only
        DB::statement(<<<'SQL'
            CREATE POLICY pem_seller_own ON purchase_evolution_metrics
                FOR SELECT
                USING (
                    current_setting('app.user_role', TRUE) = 'seller'
                    AND customer_id IN (
                        SELECT id FROM customers
                        WHERE assigned_seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                    )
                )
        SQL);

        // ----------------------------------------------------------------
        // alerts RLS
        // ----------------------------------------------------------------
        DB::statement('ALTER TABLE alerts ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE alerts FORCE ROW LEVEL SECURITY');

        // Director: sees all alerts
        DB::statement(<<<'SQL'
            CREATE POLICY alerts_director_all ON alerts
                FOR ALL
                USING (current_setting('app.user_role', TRUE) = 'director')
        SQL);

        // Distributor/Seller: own alerts only
        DB::statement(<<<'SQL'
            CREATE POLICY alerts_own ON alerts
                FOR SELECT
                USING (
                    target_user_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                )
        SQL);

        // Allow insert by the application role (AlertDispatcher inserts rows)
        DB::statement(<<<'SQL'
            CREATE POLICY alerts_insert ON alerts
                FOR INSERT
                WITH CHECK (TRUE)
        SQL);

        // Allow update (mark delivered, mark read)
        DB::statement(<<<'SQL'
            CREATE POLICY alerts_update_own ON alerts
                FOR UPDATE
                USING (
                    current_setting('app.user_role', TRUE) = 'director'
                    OR target_user_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS pem_director_all       ON purchase_evolution_metrics');
        DB::statement('DROP POLICY IF EXISTS pem_distributor_zone   ON purchase_evolution_metrics');
        DB::statement('DROP POLICY IF EXISTS pem_seller_own         ON purchase_evolution_metrics');
        DB::statement('ALTER TABLE purchase_evolution_metrics DISABLE ROW LEVEL SECURITY');

        DB::statement('DROP POLICY IF EXISTS alerts_director_all   ON alerts');
        DB::statement('DROP POLICY IF EXISTS alerts_own            ON alerts');
        DB::statement('DROP POLICY IF EXISTS alerts_insert         ON alerts');
        DB::statement('DROP POLICY IF EXISTS alerts_update_own     ON alerts');
        DB::statement('ALTER TABLE alerts DISABLE ROW LEVEL SECURITY');
    }
};
