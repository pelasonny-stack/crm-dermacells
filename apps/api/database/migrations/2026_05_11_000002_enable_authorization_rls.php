<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 11 — Row Level Security for authorization_requests.
 *
 * POLICY MATRIX
 * =============
 *
 * Director  → sees ALL rows (full queue visibility required for approval)
 * Seller    → sees only rows WHERE requested_by = current app.user_id
 * Distributor → sees only rows WHERE requested_by = current app.user_id
 *
 * Write (INSERT/UPDATE) is granted for any role at RLS level; the service
 * layer enforces business rules (only Sellers/Distributors can INSERT;
 * only Directors can UPDATE status).
 *
 * worker_role / report_role get read-only access (SELECT) for Horizon jobs
 * and reporting queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE authorization_requests ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE authorization_requests FORCE ROW LEVEL SECURITY');

        // Director: full access to all rows
        DB::statement(<<<'SQL'
            CREATE POLICY ar_director_all ON authorization_requests
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'director'
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'director'
            )
        SQL);

        // Seller: sees and modifies only their own requests
        DB::statement(<<<'SQL'
            CREATE POLICY ar_seller_own ON authorization_requests
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'seller'
                AND requested_by = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'seller'
                AND requested_by = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
            )
        SQL);

        // Distributor: sees and modifies only their own requests
        DB::statement(<<<'SQL'
            CREATE POLICY ar_distributor_own ON authorization_requests
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND requested_by = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND requested_by = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
            )
        SQL);

        // report_role and worker_role: read-only, unrestricted (for Horizon jobs / reports)
        DB::statement(<<<'SQL'
            CREATE POLICY ar_report_read ON authorization_requests
            AS PERMISSIVE FOR SELECT
            TO report_role
            USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY ar_worker_read ON authorization_requests
            AS PERMISSIVE FOR SELECT
            TO worker_role
            USING (TRUE)
        SQL);
    }

    public function down(): void
    {
        foreach ([
            'ar_director_all',
            'ar_seller_own',
            'ar_distributor_own',
            'ar_report_read',
            'ar_worker_read',
        ] as $policy) {
            DB::statement("DROP POLICY IF EXISTS {$policy} ON authorization_requests");
        }

        DB::statement('ALTER TABLE authorization_requests DISABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE authorization_requests NO FORCE ROW LEVEL SECURITY');
    }
};
