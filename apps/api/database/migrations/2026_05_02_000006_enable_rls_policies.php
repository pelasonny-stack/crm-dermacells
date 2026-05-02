<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enables Row Level Security on the tables created in this phase.
 *
 * SECURITY MODEL OVERVIEW
 * =======================
 * Every HTTP request is wrapped in a transaction by SetPostgresRlsContext
 * middleware, which executes:
 *
 *   SET LOCAL app.user_id   = '<uuid>';
 *   SET LOCAL app.user_role = 'director|distributor|seller';
 *
 * These GUCs are session-local (valid only for the current transaction) and
 * are reset at transaction end, which is compatible with PgBouncer transaction
 * mode. The policies below read them via current_setting().
 *
 * FORCE ROW LEVEL SECURITY ensures policies apply even to the table owner
 * (typically migration_role). This prevents accidental superuser bypass in
 * production console sessions.
 *
 * TABLES COVERED
 * ==============
 *   users                — Director sees all; non-director sees only self.
 *   zones                — Director all; Distributor own zone; Seller read-all
 *                          (zones are reference data Sellers read for context).
 *   customer_categories  — Director all; Seller/Distributor see non-director_only rows.
 *   payment_terms        — All authenticated users: SELECT; Director: all DML.
 *
 * NOTE: audit_log does not need RLS because app_role already has only
 * INSERT + SELECT and the BEFORE trigger blocks mutations. Directors read it
 * through Filament which uses migration_role (DDL role) in read-only mode, or
 * through a dedicated report_role connection — both bypass RLS by design and
 * are acceptable because audit_log itself is immutable.
 *
 * GUCs READ BY THESE POLICIES
 * ============================
 *   app.user_id   — UUID of the authenticated user (TEXT, cast to UUID inline)
 *   app.user_role — Role string: 'director' | 'distributor' | 'seller'
 */
return new class extends Migration
{
    public function up(): void
    {
        // ================================================================
        // USERS TABLE
        // ================================================================
        DB::statement('ALTER TABLE users ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE users FORCE ROW LEVEL SECURITY');

        // Directors see every user row (needed for admin panel, user management).
        DB::statement(<<<'SQL'
            CREATE POLICY users_director_all ON users
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'director'
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'director'
            )
        SQL);

        // Non-directors (distributor, seller) can only read their own row.
        // They never need to INSERT or UPDATE users (director-only operation).
        DB::statement(<<<'SQL'
            CREATE POLICY users_self_read ON users
            AS PERMISSIVE FOR SELECT
            TO app_role
            USING (
                id = NULLIF(current_setting('app.user_id', TRUE), '')::UUID
            )
        SQL);

        // worker_role needs to read users for job processing (e.g. push notifications).
        DB::statement(<<<'SQL'
            CREATE POLICY users_worker_read ON users
            AS PERMISSIVE FOR SELECT
            TO worker_role
            USING (TRUE)
        SQL);

        // report_role: read-only, unrestricted (used for Filament audit viewer
        // and exported reports that are Director-gated at the application layer).
        DB::statement(<<<'SQL'
            CREATE POLICY users_report_read ON users
            AS PERMISSIVE FOR SELECT
            TO report_role
            USING (TRUE)
        SQL);

        // ================================================================
        // ZONES TABLE
        // ================================================================
        DB::statement('ALTER TABLE zones ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE zones FORCE ROW LEVEL SECURITY');

        // Directors: full access.
        DB::statement(<<<'SQL'
            CREATE POLICY zones_director_all ON zones
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'director'
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'director'
            )
        SQL);

        // Distributors: can read and update only their own zone(s).
        // A distributor may be assigned to multiple zones (zones.distributor_id = user).
        DB::statement(<<<'SQL'
            CREATE POLICY zones_distributor_own ON zones
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

        // Sellers: read-only on all active zones (zones are reference data;
        // sellers need to know which zone a client belongs to).
        DB::statement(<<<'SQL'
            CREATE POLICY zones_seller_read ON zones
            AS PERMISSIVE FOR SELECT
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'seller'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY zones_worker_read ON zones
            AS PERMISSIVE FOR SELECT
            TO worker_role
            USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY zones_report_read ON zones
            AS PERMISSIVE FOR SELECT
            TO report_role
            USING (TRUE)
        SQL);

        // ================================================================
        // CUSTOMER CATEGORIES TABLE
        // ================================================================
        DB::statement('ALTER TABLE customer_categories ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE customer_categories FORCE ROW LEVEL SECURITY');

        // Directors: full access including director_only categories (e.g. D).
        DB::statement(<<<'SQL'
            CREATE POLICY categories_director_all ON customer_categories
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'director'
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'director'
            )
        SQL);

        // Sellers and distributors: read non-director_only categories only.
        // Category D (director_only = TRUE) is invisible to them (§3.3).
        DB::statement(<<<'SQL'
            CREATE POLICY categories_non_director_read ON customer_categories
            AS PERMISSIVE FOR SELECT
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) IN ('distributor', 'seller')
                AND director_only = FALSE
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY categories_worker_read ON customer_categories
            AS PERMISSIVE FOR SELECT
            TO worker_role
            USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY categories_report_read ON customer_categories
            AS PERMISSIVE FOR SELECT
            TO report_role
            USING (TRUE)
        SQL);

        // ================================================================
        // PAYMENT TERMS TABLE
        // ================================================================
        DB::statement('ALTER TABLE payment_terms ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE payment_terms FORCE ROW LEVEL SECURITY');

        // All roles read all active payment terms (reference data needed when
        // creating sales and customers).
        DB::statement(<<<'SQL'
            CREATE POLICY payment_terms_read_all ON payment_terms
            AS PERMISSIVE FOR SELECT
            TO app_role
            USING (TRUE)
        SQL);

        // Only directors can create or modify payment terms (§16.6).
        DB::statement(<<<'SQL'
            CREATE POLICY payment_terms_director_write ON payment_terms
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
            CREATE POLICY payment_terms_worker_read ON payment_terms
            AS PERMISSIVE FOR SELECT
            TO worker_role
            USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY payment_terms_report_read ON payment_terms
            AS PERMISSIVE FOR SELECT
            TO report_role
            USING (TRUE)
        SQL);
    }

    public function down(): void
    {
        // Drop policies and disable RLS in reverse dependency order.
        $tables = [
            'payment_terms'       => ['payment_terms_read_all', 'payment_terms_director_write', 'payment_terms_worker_read', 'payment_terms_report_read'],
            'customer_categories' => ['categories_director_all', 'categories_non_director_read', 'categories_worker_read', 'categories_report_read'],
            'zones'               => ['zones_director_all', 'zones_distributor_own', 'zones_seller_read', 'zones_worker_read', 'zones_report_read'],
            'users'               => ['users_director_all', 'users_self_read', 'users_worker_read', 'users_report_read'],
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
