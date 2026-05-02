<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row Level Security policies for Phase 7 payment tables.
 *
 * POLICY MATRIX
 * =============
 *
 * payments:
 *   Director     → ALL rows
 *   Distributor  → rows where the associated sale's zone_id is in their managed zones
 *   Seller       → rows where the associated sale's seller_id = current user
 *
 * customer_account_balances:
 *   Director     → ALL rows
 *   Distributor  → rows for customers in their zone
 *   Seller       → rows for their own assigned customers
 *
 * customer_credit_balances:
 *   Director     → ALL rows
 *   Distributor  → rows for customers in their zone (via customer_id subquery)
 *   Seller       → rows for their own assigned customers
 *
 * payment_methods:
 *   All roles    → SELECT only (lookup table, no RLS write restriction needed)
 *
 * GUCs (set per-transaction by SetPostgresRlsContext middleware):
 *   app.user_id   UUID of the authenticated user
 *   app.user_role 'director' | 'distributor' | 'seller'
 */
return new class extends Migration
{
    public function up(): void
    {
        // ====================================================================
        // payment_methods — read-only for all roles, no sensitive data
        // ====================================================================
        DB::statement('ALTER TABLE payment_methods ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE payment_methods FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY payment_methods_all_read ON payment_methods
            AS PERMISSIVE FOR SELECT
            TO app_role
            USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY payment_methods_director_write ON payment_methods
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (current_setting('app.user_role', TRUE) = 'director')
            WITH CHECK (current_setting('app.user_role', TRUE) = 'director')
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY payment_methods_worker_read ON payment_methods
            AS PERMISSIVE FOR SELECT TO worker_role USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY payment_methods_report_read ON payment_methods
            AS PERMISSIVE FOR SELECT TO report_role USING (TRUE)
        SQL);

        // ====================================================================
        // payments
        // ====================================================================
        DB::statement('ALTER TABLE payments ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE payments FORCE ROW LEVEL SECURITY');

        // Director: full access to all payments
        DB::statement(<<<'SQL'
            CREATE POLICY payments_director_all ON payments
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (current_setting('app.user_role', TRUE) = 'director')
            WITH CHECK (current_setting('app.user_role', TRUE) = 'director')
        SQL);

        // Distributor: sees payments where the parent sale is in their zone
        DB::statement(<<<'SQL'
            CREATE POLICY payments_distributor_zone ON payments
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND sale_id IN (
                    SELECT id FROM sales
                    WHERE zone_id IN (
                        SELECT id FROM zones
                        WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
                    )
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND sale_id IN (
                    SELECT id FROM sales
                    WHERE zone_id IN (
                        SELECT id FROM zones
                        WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
                    )
                )
            )
        SQL);

        // Seller: sees only payments on their own sales
        DB::statement(<<<'SQL'
            CREATE POLICY payments_seller_own ON payments
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'seller'
                AND sale_id IN (
                    SELECT id FROM sales
                    WHERE seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'seller'
                AND sale_id IN (
                    SELECT id FROM sales
                    WHERE seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY payments_worker_read ON payments
            AS PERMISSIVE FOR SELECT TO worker_role USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY payments_report_read ON payments
            AS PERMISSIVE FOR SELECT TO report_role USING (TRUE)
        SQL);

        // ====================================================================
        // customer_account_balances
        // ====================================================================
        DB::statement('ALTER TABLE customer_account_balances ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE customer_account_balances FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY cab_director_all ON customer_account_balances
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (current_setting('app.user_role', TRUE) = 'director')
            WITH CHECK (current_setting('app.user_role', TRUE) = 'director')
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY cab_distributor_zone ON customer_account_balances
            AS PERMISSIVE FOR SELECT
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND customer_id IN (
                    SELECT id FROM customers
                    WHERE zone_id IN (
                        SELECT id FROM zones
                        WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
                    )
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY cab_seller_own ON customer_account_balances
            AS PERMISSIVE FOR SELECT
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'seller'
                AND customer_id IN (
                    SELECT id FROM customers
                    WHERE assigned_seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY cab_worker_read ON customer_account_balances
            AS PERMISSIVE FOR SELECT TO worker_role USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY cab_report_read ON customer_account_balances
            AS PERMISSIVE FOR SELECT TO report_role USING (TRUE)
        SQL);

        // ====================================================================
        // customer_credit_balances
        // ====================================================================
        DB::statement('ALTER TABLE customer_credit_balances ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE customer_credit_balances FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY ccb_director_all ON customer_credit_balances
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (current_setting('app.user_role', TRUE) = 'director')
            WITH CHECK (current_setting('app.user_role', TRUE) = 'director')
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY ccb_distributor_zone ON customer_credit_balances
            AS PERMISSIVE FOR SELECT
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND customer_id IN (
                    SELECT id FROM customers
                    WHERE zone_id IN (
                        SELECT id FROM zones
                        WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
                    )
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY ccb_seller_own ON customer_credit_balances
            AS PERMISSIVE FOR SELECT
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'seller'
                AND customer_id IN (
                    SELECT id FROM customers
                    WHERE assigned_seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY ccb_worker_read ON customer_credit_balances
            AS PERMISSIVE FOR SELECT TO worker_role USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY ccb_report_read ON customer_credit_balances
            AS PERMISSIVE FOR SELECT TO report_role USING (TRUE)
        SQL);
    }

    public function down(): void
    {
        $tablePolicies = [
            'customer_credit_balances' => [
                'ccb_director_all', 'ccb_distributor_zone', 'ccb_seller_own',
                'ccb_worker_read', 'ccb_report_read',
            ],
            'customer_account_balances' => [
                'cab_director_all', 'cab_distributor_zone', 'cab_seller_own',
                'cab_worker_read', 'cab_report_read',
            ],
            'payments' => [
                'payments_director_all', 'payments_distributor_zone', 'payments_seller_own',
                'payments_worker_read', 'payments_report_read',
            ],
            'payment_methods' => [
                'payment_methods_all_read', 'payment_methods_director_write',
                'payment_methods_worker_read', 'payment_methods_report_read',
            ],
        ];

        foreach ($tablePolicies as $table => $policies) {
            foreach ($policies as $policy) {
                DB::statement("DROP POLICY IF EXISTS {$policy} ON {$table}");
            }
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        }
    }
};
