<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reverts customer RLS policies from is_director_only_category() function to
 * the original inline subquery approach, now that customer_categories has an
 * open SELECT policy (2026_05_19_000002) that allows all roles to read all
 * category rows including director_only=TRUE ones.
 *
 * ROOT CAUSE OF FUNCTION APPROACH FAILURE
 * ========================================
 * is_director_only_category() is SECURITY DEFINER but PostgreSQL evaluates RLS
 * in SECURITY DEFINER functions based on the SESSION USER (app_role), not the
 * function owner (migration_role). The SET row_security = off function config
 * only has effect when the calling user has BYPASSRLS — app_role does not.
 *
 * SOLUTION
 * ========
 * With customer_categories now open for SELECT to all roles (migration _000002),
 * the original inline subquery works correctly:
 *
 *   AND category_id NOT IN (
 *       SELECT id FROM customer_categories WHERE director_only = TRUE
 *   )
 *
 * Under seller GUC, this subquery now returns the director_only category IDs
 * (since sellers can read all categories), correctly hiding catD customers.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Customers table
        DB::statement('DROP POLICY IF EXISTS customers_distributor_zone ON customers');
        DB::statement('DROP POLICY IF EXISTS customers_seller_own ON customers');

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
                    SELECT id FROM customer_categories WHERE director_only = TRUE
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND zone_id IN (
                    SELECT id FROM zones
                    WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                )
                AND category_id NOT IN (
                    SELECT id FROM customer_categories WHERE director_only = TRUE
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY customers_seller_own ON customers
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'seller'
                AND assigned_seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                AND category_id NOT IN (
                    SELECT id FROM customer_categories WHERE director_only = TRUE
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'seller'
                AND assigned_seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                AND category_id NOT IN (
                    SELECT id FROM customer_categories WHERE director_only = TRUE
                )
            )
        SQL);

        // Customer billing entities
        DB::statement('DROP POLICY IF EXISTS billing_entities_distributor_zone ON customer_billing_entities');
        DB::statement('DROP POLICY IF EXISTS billing_entities_seller_own ON customer_billing_entities');

        DB::statement(<<<'SQL'
            CREATE POLICY billing_entities_distributor_zone ON customer_billing_entities
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND EXISTS (
                    SELECT 1 FROM customers c
                    WHERE c.id = customer_billing_entities.customer_id
                      AND c.zone_id IN (SELECT id FROM zones WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID)
                      AND c.category_id NOT IN (SELECT id FROM customer_categories WHERE director_only = TRUE)
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND EXISTS (
                    SELECT 1 FROM customers c
                    WHERE c.id = customer_billing_entities.customer_id
                      AND c.zone_id IN (SELECT id FROM zones WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID)
                      AND c.category_id NOT IN (SELECT id FROM customer_categories WHERE director_only = TRUE)
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
                      AND c.category_id NOT IN (SELECT id FROM customer_categories WHERE director_only = TRUE)
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'seller'
                AND EXISTS (
                    SELECT 1 FROM customers c
                    WHERE c.id = customer_billing_entities.customer_id
                      AND c.assigned_seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                      AND c.category_id NOT IN (SELECT id FROM customer_categories WHERE director_only = TRUE)
                )
            )
        SQL);

        // Customer contacts
        DB::statement('DROP POLICY IF EXISTS contacts_distributor_zone ON customer_contacts');
        DB::statement('DROP POLICY IF EXISTS contacts_seller_own ON customer_contacts');

        DB::statement(<<<'SQL'
            CREATE POLICY contacts_distributor_zone ON customer_contacts
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND EXISTS (
                    SELECT 1 FROM customers c
                    WHERE c.id = customer_contacts.customer_id
                      AND c.zone_id IN (SELECT id FROM zones WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID)
                      AND c.category_id NOT IN (SELECT id FROM customer_categories WHERE director_only = TRUE)
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND EXISTS (
                    SELECT 1 FROM customers c
                    WHERE c.id = customer_contacts.customer_id
                      AND c.zone_id IN (SELECT id FROM zones WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID)
                      AND c.category_id NOT IN (SELECT id FROM customer_categories WHERE director_only = TRUE)
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
                      AND c.category_id NOT IN (SELECT id FROM customer_categories WHERE director_only = TRUE)
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'seller'
                AND EXISTS (
                    SELECT 1 FROM customers c
                    WHERE c.id = customer_contacts.customer_id
                      AND c.assigned_seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                      AND c.category_id NOT IN (SELECT id FROM customer_categories WHERE director_only = TRUE)
                )
            )
        SQL);

        // Scheduled actions
        DB::statement('DROP POLICY IF EXISTS scheduled_actions_distributor_zone ON scheduled_actions');
        DB::statement('DROP POLICY IF EXISTS scheduled_actions_seller_own ON scheduled_actions');

        DB::statement(<<<'SQL'
            CREATE POLICY scheduled_actions_distributor_zone ON scheduled_actions
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND EXISTS (
                    SELECT 1 FROM customers c
                    WHERE c.id = scheduled_actions.customer_id
                      AND c.zone_id IN (SELECT id FROM zones WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID)
                      AND c.category_id NOT IN (SELECT id FROM customer_categories WHERE director_only = TRUE)
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND EXISTS (
                    SELECT 1 FROM customers c
                    WHERE c.id = scheduled_actions.customer_id
                      AND c.zone_id IN (SELECT id FROM zones WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID)
                      AND c.category_id NOT IN (SELECT id FROM customer_categories WHERE director_only = TRUE)
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
                      AND c.category_id NOT IN (SELECT id FROM customer_categories WHERE director_only = TRUE)
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'seller'
                AND EXISTS (
                    SELECT 1 FROM customers c
                    WHERE c.id = scheduled_actions.customer_id
                      AND c.assigned_seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                      AND c.category_id NOT IN (SELECT id FROM customer_categories WHERE director_only = TRUE)
                )
            )
        SQL);
    }

    public function down(): void
    {
        // Restore is_director_only_category() function-based policies
        // (see 2026_05_17_000002_patch_customer_category_d_rls)
    }
};
