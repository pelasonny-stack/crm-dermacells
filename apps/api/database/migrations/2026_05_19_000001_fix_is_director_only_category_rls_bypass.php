<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fixes is_director_only_category() to actually bypass RLS on customer_categories.
 *
 * ROOT CAUSE
 * ==========
 * The original SECURITY DEFINER function owned by migration_role (BYPASSRLS)
 * was expected to bypass RLS when querying customer_categories. However,
 * PostgreSQL evaluates row security based on the SESSION USER (app_role), not
 * the function owner, even for SECURITY DEFINER functions. Since customer_categories
 * has FORCE ROW LEVEL SECURITY and app_role lacks BYPASSRLS, the
 * categories_non_director_read policy hides director_only=TRUE rows inside
 * the function — making COALESCE(NULL, FALSE) always return FALSE.
 *
 * FIX
 * ===
 * Rewrite as plpgsql with SET LOCAL row_security = OFF. When the function owner
 * (migration_role) has BYPASSRLS and the function sets row_security = OFF, the
 * RLS check is bypassed for that query, allowing the function to see
 * director_only=TRUE categories regardless of the calling user's GUC.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION is_director_only_category(cat_id UUID)
            RETURNS BOOLEAN
            LANGUAGE plpgsql
            VOLATILE
            SECURITY DEFINER
            SET search_path = public
            SET row_security = off
            AS $$
            DECLARE
                result BOOLEAN;
            BEGIN
                SELECT COALESCE(
                    (SELECT director_only FROM customer_categories WHERE id = cat_id),
                    FALSE
                ) INTO result;
                RETURN result;
            END;
            $$
        SQL);
    }

    public function down(): void
    {
        // Restore the original sql-language version (non-bypassing)
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
    }
};
