<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Patches customer_categories RLS so that all roles can SELECT any category row,
 * including director_only = TRUE ones.
 *
 * ROOT CAUSE
 * ==========
 * The is_director_only_category() SECURITY DEFINER function queries
 * customer_categories to determine if a category is director-only. PostgreSQL
 * evaluates RLS in SECURITY DEFINER functions based on the SESSION USER
 * (app_role), not the function owner (migration_role). Since customer_categories
 * has FORCE ROW LEVEL SECURITY and the categories_non_director_read policy
 * hides director_only=TRUE rows from sellers/distributors, the function's
 * subquery returns NULL for those categories — making COALESCE(NULL, FALSE)
 * return FALSE, which means the customer appears visible to the seller.
 *
 * FIX
 * ===
 * Add a permissive SELECT-all policy on customer_categories for app_role, so
 * any authenticated role can read all category rows. This is safe because:
 *  1. Category metadata (name, code, director_only flag) is not sensitive data.
 *  2. The customer-level RLS policies still enforce which customers are visible.
 *     Sellers cannot see category D customers even though they can now read
 *     the category D category row.
 *
 * The existing categories_non_director_read policy (which restricted sellers to
 * non-director_only categories) is dropped since it no longer serves a security
 * purpose — it was only restricting category metadata visibility, not customer data.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop the restrictive policy that was hiding director_only categories
        // from sellers and distributors. This was causing the function to return
        // FALSE for director_only categories under seller/distributor GUC.
        DB::statement('DROP POLICY IF EXISTS categories_non_director_read ON customer_categories');

        // Add an open SELECT policy for all app_role users so the
        // is_director_only_category() function can correctly identify director_only
        // categories regardless of the calling user's GUC.
        DB::statement(<<<'SQL'
            CREATE POLICY categories_all_read ON customer_categories
            AS PERMISSIVE FOR SELECT
            TO app_role
            USING (TRUE)
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS categories_all_read ON customer_categories');

        // Restore the original restrictive policy
        DB::statement(<<<'SQL'
            CREATE POLICY categories_non_director_read ON customer_categories
            AS PERMISSIVE FOR SELECT
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) IN ('distributor', 'seller')
                AND director_only = FALSE
            )
        SQL);
    }
};
