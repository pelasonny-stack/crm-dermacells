<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Patches the customers (and child-table) RLS policies to correctly exclude
 * Category-D customers from Seller and Distributor visibility.
 *
 * ROOT CAUSE
 * ==========
 * The original customer policies used a subquery:
 *
 *   category_id NOT IN (
 *       SELECT id FROM customer_categories WHERE director_only = TRUE
 *   )
 *
 * The subquery runs under the same RLS context as the outer query. Under seller
 * or distributor GUC, the `categories_non_director_read` policy on
 * customer_categories hides all rows where director_only = TRUE. So the
 * subquery always returns an empty set, making NOT IN (...) always evaluate to
 * TRUE — i.e., Category-D customers become visible to Sellers/Distributors.
 *
 * FIX
 * ===
 * Introduce a SECURITY DEFINER function `is_director_only_category(uuid)` that
 * queries customer_categories as the function owner (migration_role / superuser)
 * and therefore bypasses RLS. The customer policies call this function instead
 * of the inline subquery.
 *
 * The function is created as STABLE so Postgres can cache the result across
 * rows of the same query, avoiding repeated table scans.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Create a SECURITY DEFINER function owned by the schema owner so it
        // always runs with BYPASSRLS privileges regardless of the caller's GUC.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION is_director_only_category(cat_id UUID)
            RETURNS BOOLEAN
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            AS $$
                SELECT COALESCE(
                    (SELECT director_only FROM customer_categories WHERE id = cat_id),
                    FALSE
                )
            $$
        SQL);

        // =====================================================================
        // CUSTOMERS TABLE — replace distributor and seller policies
        // =====================================================================
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
                AND is_director_only_category(category_id) = FALSE
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND zone_id IN (
                    SELECT id FROM zones
                    WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                )
                AND is_director_only_category(category_id) = FALSE
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY customers_seller_own ON customers
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'seller'
                AND assigned_seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                AND is_director_only_category(category_id) = FALSE
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'seller'
                AND assigned_seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                AND is_director_only_category(category_id) = FALSE
            )
        SQL);

        // =====================================================================
        // CUSTOMER_BILLING_ENTITIES TABLE — replace distributor and seller policies
        // =====================================================================
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
                      AND c.zone_id IN (
                          SELECT id FROM zones
                          WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                      )
                      AND is_director_only_category(c.category_id) = FALSE
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
                      AND is_director_only_category(c.category_id) = FALSE
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
                      AND is_director_only_category(c.category_id) = FALSE
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'seller'
                AND EXISTS (
                    SELECT 1 FROM customers c
                    WHERE c.id = customer_billing_entities.customer_id
                      AND c.assigned_seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                      AND is_director_only_category(c.category_id) = FALSE
                )
            )
        SQL);

        // =====================================================================
        // CUSTOMER_CONTACTS TABLE — replace distributor and seller policies
        // =====================================================================
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
                      AND c.zone_id IN (
                          SELECT id FROM zones
                          WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                      )
                      AND is_director_only_category(c.category_id) = FALSE
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
                      AND is_director_only_category(c.category_id) = FALSE
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
                      AND is_director_only_category(c.category_id) = FALSE
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'seller'
                AND EXISTS (
                    SELECT 1 FROM customers c
                    WHERE c.id = customer_contacts.customer_id
                      AND c.assigned_seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                      AND is_director_only_category(c.category_id) = FALSE
                )
            )
        SQL);

        // =====================================================================
        // SCHEDULED_ACTIONS TABLE — replace distributor and seller policies
        // =====================================================================
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
                      AND c.zone_id IN (
                          SELECT id FROM zones
                          WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                      )
                      AND is_director_only_category(c.category_id) = FALSE
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
                      AND is_director_only_category(c.category_id) = FALSE
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
                      AND is_director_only_category(c.category_id) = FALSE
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'seller'
                AND EXISTS (
                    SELECT 1 FROM customers c
                    WHERE c.id = scheduled_actions.customer_id
                      AND c.assigned_seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                      AND is_director_only_category(c.category_id) = FALSE
                )
            )
        SQL);
    }

    public function down(): void
    {
        // Restore original subquery-based policies
        DB::statement('DROP POLICY IF EXISTS customers_distributor_zone ON customers');
        DB::statement('DROP POLICY IF EXISTS customers_seller_own ON customers');

        DB::statement(<<<'SQL'
            CREATE POLICY customers_distributor_zone ON customers
            AS PERMISSIVE FOR ALL TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND zone_id IN (SELECT id FROM zones WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID)
                AND category_id NOT IN (SELECT id FROM customer_categories WHERE director_only = TRUE)
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND zone_id IN (SELECT id FROM zones WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID)
                AND category_id NOT IN (SELECT id FROM customer_categories WHERE director_only = TRUE)
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY customers_seller_own ON customers
            AS PERMISSIVE FOR ALL TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'seller'
                AND assigned_seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                AND category_id NOT IN (SELECT id FROM customer_categories WHERE director_only = TRUE)
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'seller'
                AND assigned_seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
                AND category_id NOT IN (SELECT id FROM customer_categories WHERE director_only = TRUE)
            )
        SQL);

        DB::statement('DROP FUNCTION IF EXISTS is_director_only_category(UUID)');
    }
};
