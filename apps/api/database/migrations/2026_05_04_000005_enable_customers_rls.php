<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enables Row Level Security on the customer domain tables.
 *
 * SECURITY MODEL — CUSTOMERS
 * ==========================
 * Three principals with different visibility scopes (§2.4):
 *
 *   Director:     Sees ALL customers, including Category D (director_only = true).
 *   Distributor:  Sees customers whose zone is assigned to them.
 *                 customers.zone_id IN (SELECT id FROM zones WHERE distributor_id = app.user_id)
 *   Seller:       Sees ONLY customers where assigned_seller_id = app.user_id.
 *
 * CATEGORY-D PROTECTION (§3.1, §3.3)
 * ====================================
 * Sellers AND Distributors are DENIED access to customers in a category that
 * has director_only = true (currently category D — Distribuidor-cliente).
 * This is enforced as an ADDITIONAL filter in both the distributor and seller
 * policies (both USING and WITH CHECK include the director_only = false guard).
 *
 * CHILD TABLES (billing_entities, contacts, scheduled_actions)
 * =============================================================
 * These tables have a CASCADE FK to customers. Their RLS policies use a
 * correlated subquery to `customers` so the same zone/seller/director-only
 * logic is inherited without duplicating the predicate logic.
 *
 * worker_role: SELECT-only, unrestricted (Horizon jobs need cross-row access
 * for birthday dispatch, scheduled action dispatch, audit chain verification).
 *
 * report_role: SELECT-only, unrestricted (Filament Director views, exports).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ====================================================================
        // CUSTOMERS TABLE
        // ====================================================================
        DB::statement('ALTER TABLE customers ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE customers FORCE ROW LEVEL SECURITY');

        // Director: full access to all customers including category D
        DB::statement(<<<'SQL'
            CREATE POLICY customers_director_all ON customers
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'director'
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'director'
            )
        SQL);

        // Distributor: sees customers in their zone(s), excluding category D
        DB::statement(<<<'SQL'
            CREATE POLICY customers_distributor_zone ON customers
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND zone_id IN (
                    SELECT id FROM zones
                    WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                )
                AND category_id NOT IN (
                    SELECT id FROM customer_categories
                    WHERE director_only = TRUE
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND zone_id IN (
                    SELECT id FROM zones
                    WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                )
                AND category_id NOT IN (
                    SELECT id FROM customer_categories
                    WHERE director_only = TRUE
                )
            )
        SQL);

        // Seller: sees only their own assigned customers, excluding category D
        DB::statement(<<<'SQL'
            CREATE POLICY customers_seller_own ON customers
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'seller'
                AND assigned_seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                AND category_id NOT IN (
                    SELECT id FROM customer_categories
                    WHERE director_only = TRUE
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'seller'
                AND assigned_seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                AND category_id NOT IN (
                    SELECT id FROM customer_categories
                    WHERE director_only = TRUE
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY customers_worker_read ON customers
            AS PERMISSIVE FOR SELECT
            TO worker_role
            USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY customers_report_read ON customers
            AS PERMISSIVE FOR SELECT
            TO report_role
            USING (TRUE)
        SQL);

        // ====================================================================
        // CUSTOMER_BILLING_ENTITIES TABLE
        // ====================================================================
        DB::statement('ALTER TABLE customer_billing_entities ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE customer_billing_entities FORCE ROW LEVEL SECURITY');

        // Inherits visibility from customers via correlated subquery
        DB::statement(<<<'SQL'
            CREATE POLICY billing_entities_director_all ON customer_billing_entities
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
            CREATE POLICY billing_entities_distributor_zone ON customer_billing_entities
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND EXISTS (
                    SELECT 1 FROM customers c
                    WHERE c.id = customer_billing_entities.customer_id
                      AND c.zone_id IN (
                          SELECT id FROM zones
                          WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                      )
                      AND c.category_id NOT IN (
                          SELECT id FROM customer_categories WHERE director_only = TRUE
                      )
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND EXISTS (
                    SELECT 1 FROM customers c
                    WHERE c.id = customer_billing_entities.customer_id
                      AND c.zone_id IN (
                          SELECT id FROM zones
                          WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                      )
                      AND c.category_id NOT IN (
                          SELECT id FROM customer_categories WHERE director_only = TRUE
                      )
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY billing_entities_seller_own ON customer_billing_entities
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'seller'
                AND EXISTS (
                    SELECT 1 FROM customers c
                    WHERE c.id = customer_billing_entities.customer_id
                      AND c.assigned_seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                      AND c.category_id NOT IN (
                          SELECT id FROM customer_categories WHERE director_only = TRUE
                      )
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'seller'
                AND EXISTS (
                    SELECT 1 FROM customers c
                    WHERE c.id = customer_billing_entities.customer_id
                      AND c.assigned_seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                      AND c.category_id NOT IN (
                          SELECT id FROM customer_categories WHERE director_only = TRUE
                      )
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY billing_entities_worker_read ON customer_billing_entities
            AS PERMISSIVE FOR SELECT
            TO worker_role
            USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY billing_entities_report_read ON customer_billing_entities
            AS PERMISSIVE FOR SELECT
            TO report_role
            USING (TRUE)
        SQL);

        // ====================================================================
        // CUSTOMER_CONTACTS TABLE
        // ====================================================================
        DB::statement('ALTER TABLE customer_contacts ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE customer_contacts FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY contacts_director_all ON customer_contacts
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
            CREATE POLICY contacts_distributor_zone ON customer_contacts
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND EXISTS (
                    SELECT 1 FROM customers c
                    WHERE c.id = customer_contacts.customer_id
                      AND c.zone_id IN (
                          SELECT id FROM zones
                          WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                      )
                      AND c.category_id NOT IN (
                          SELECT id FROM customer_categories WHERE director_only = TRUE
                      )
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND EXISTS (
                    SELECT 1 FROM customers c
                    WHERE c.id = customer_contacts.customer_id
                      AND c.zone_id IN (
                          SELECT id FROM zones
                          WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                      )
                      AND c.category_id NOT IN (
                          SELECT id FROM customer_categories WHERE director_only = TRUE
                      )
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY contacts_seller_own ON customer_contacts
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'seller'
                AND EXISTS (
                    SELECT 1 FROM customers c
                    WHERE c.id = customer_contacts.customer_id
                      AND c.assigned_seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                      AND c.category_id NOT IN (
                          SELECT id FROM customer_categories WHERE director_only = TRUE
                      )
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'seller'
                AND EXISTS (
                    SELECT 1 FROM customers c
                    WHERE c.id = customer_contacts.customer_id
                      AND c.assigned_seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                      AND c.category_id NOT IN (
                          SELECT id FROM customer_categories WHERE director_only = TRUE
                      )
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY contacts_worker_read ON customer_contacts
            AS PERMISSIVE FOR SELECT
            TO worker_role
            USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY contacts_report_read ON customer_contacts
            AS PERMISSIVE FOR SELECT
            TO report_role
            USING (TRUE)
        SQL);

        // ====================================================================
        // SCHEDULED_ACTIONS TABLE
        // ====================================================================
        DB::statement('ALTER TABLE scheduled_actions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE scheduled_actions FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY scheduled_actions_director_all ON scheduled_actions
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
            CREATE POLICY scheduled_actions_distributor_zone ON scheduled_actions
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND EXISTS (
                    SELECT 1 FROM customers c
                    WHERE c.id = scheduled_actions.customer_id
                      AND c.zone_id IN (
                          SELECT id FROM zones
                          WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                      )
                      AND c.category_id NOT IN (
                          SELECT id FROM customer_categories WHERE director_only = TRUE
                      )
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND EXISTS (
                    SELECT 1 FROM customers c
                    WHERE c.id = scheduled_actions.customer_id
                      AND c.zone_id IN (
                          SELECT id FROM zones
                          WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                      )
                      AND c.category_id NOT IN (
                          SELECT id FROM customer_categories WHERE director_only = TRUE
                      )
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY scheduled_actions_seller_own ON scheduled_actions
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'seller'
                AND EXISTS (
                    SELECT 1 FROM customers c
                    WHERE c.id = scheduled_actions.customer_id
                      AND c.assigned_seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                      AND c.category_id NOT IN (
                          SELECT id FROM customer_categories WHERE director_only = TRUE
                      )
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'seller'
                AND EXISTS (
                    SELECT 1 FROM customers c
                    WHERE c.id = scheduled_actions.customer_id
                      AND c.assigned_seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                      AND c.category_id NOT IN (
                          SELECT id FROM customer_categories WHERE director_only = TRUE
                      )
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY scheduled_actions_worker_read ON scheduled_actions
            AS PERMISSIVE FOR SELECT
            TO worker_role
            USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY scheduled_actions_report_read ON scheduled_actions
            AS PERMISSIVE FOR SELECT
            TO report_role
            USING (TRUE)
        SQL);
    }

    public function down(): void
    {
        $tablePolicies = [
            'scheduled_actions' => [
                'scheduled_actions_director_all',
                'scheduled_actions_distributor_zone',
                'scheduled_actions_seller_own',
                'scheduled_actions_worker_read',
                'scheduled_actions_report_read',
            ],
            'customer_contacts' => [
                'contacts_director_all',
                'contacts_distributor_zone',
                'contacts_seller_own',
                'contacts_worker_read',
                'contacts_report_read',
            ],
            'customer_billing_entities' => [
                'billing_entities_director_all',
                'billing_entities_distributor_zone',
                'billing_entities_seller_own',
                'billing_entities_worker_read',
                'billing_entities_report_read',
            ],
            'customers' => [
                'customers_director_all',
                'customers_distributor_zone',
                'customers_seller_own',
                'customers_worker_read',
                'customers_report_read',
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
