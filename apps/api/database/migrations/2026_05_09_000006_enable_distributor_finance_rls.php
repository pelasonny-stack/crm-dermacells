<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row Level Security for Phase 8 — Distribuidor Financiero tables.
 *
 * POLICY MATRIX
 * =============
 *
 * distributor_preferred_cost:
 *   Director     → full access (ALL rows)
 *   Distributor  → SELECT only on own rows (distributor_id = user_id)
 *   Seller       → no access
 *
 * distributor_account:
 *   Director     → full access
 *   Distributor  → SELECT/UPDATE own row
 *   Seller       → no access
 *
 * distributor_settlements:
 *   Director     → full access (confirms/rejects)
 *   Distributor  → SELECT/INSERT own rows (distributor_id = user_id)
 *   Seller       → no access
 *
 * seller_commissions_config:
 *   Director     → full access
 *   Distributor  → SELECT/INSERT/UPDATE where distributor_id = user_id
 *   Seller       → SELECT where seller_id = user_id (can see their own % rates)
 *
 * distributor_commission_payments:
 *   Director     → full access
 *   Distributor  → SELECT/INSERT/UPDATE where distributor_id = user_id (payer)
 *   Seller       → SELECT where seller_id = user_id (payee — can see own commissions)
 *
 * GUCs (set per-transaction by SetPostgresRlsContext middleware):
 *   app.user_id   — UUID of the authenticated user
 *   app.user_role — 'director' | 'distributor' | 'seller'
 */
return new class extends Migration
{
    public function up(): void
    {
        // ================================================================
        // distributor_preferred_cost
        // ================================================================
        DB::statement('ALTER TABLE distributor_preferred_cost ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE distributor_preferred_cost FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY dpc_director_all ON distributor_preferred_cost
            AS PERMISSIVE FOR ALL TO app_role
            USING (current_setting('app.user_role', TRUE) = 'director')
            WITH CHECK (current_setting('app.user_role', TRUE) = 'director')
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY dpc_distributor_own ON distributor_preferred_cost
            AS PERMISSIVE FOR SELECT TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY dpc_report_read ON distributor_preferred_cost
            AS PERMISSIVE FOR SELECT TO report_role USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY dpc_worker_read ON distributor_preferred_cost
            AS PERMISSIVE FOR SELECT TO worker_role USING (TRUE)
        SQL);

        // ================================================================
        // distributor_account
        // ================================================================
        DB::statement('ALTER TABLE distributor_account ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE distributor_account FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY da_director_all ON distributor_account
            AS PERMISSIVE FOR ALL TO app_role
            USING (current_setting('app.user_role', TRUE) = 'director')
            WITH CHECK (current_setting('app.user_role', TRUE) = 'director')
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY da_distributor_own ON distributor_account
            AS PERMISSIVE FOR ALL TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY da_report_read ON distributor_account
            AS PERMISSIVE FOR SELECT TO report_role USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY da_worker_read ON distributor_account
            AS PERMISSIVE FOR SELECT TO worker_role USING (TRUE)
        SQL);

        // ================================================================
        // distributor_settlements
        // ================================================================
        DB::statement('ALTER TABLE distributor_settlements ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE distributor_settlements FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY ds_director_all ON distributor_settlements
            AS PERMISSIVE FOR ALL TO app_role
            USING (current_setting('app.user_role', TRUE) = 'director')
            WITH CHECK (current_setting('app.user_role', TRUE) = 'director')
        SQL);

        // Distributor can list and create their own settlements
        DB::statement(<<<'SQL'
            CREATE POLICY ds_distributor_own ON distributor_settlements
            AS PERMISSIVE FOR ALL TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY ds_report_read ON distributor_settlements
            AS PERMISSIVE FOR SELECT TO report_role USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY ds_worker_read ON distributor_settlements
            AS PERMISSIVE FOR SELECT TO worker_role USING (TRUE)
        SQL);

        // ================================================================
        // seller_commissions_config
        // ================================================================
        DB::statement('ALTER TABLE seller_commissions_config ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE seller_commissions_config FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY scc_director_all ON seller_commissions_config
            AS PERMISSIVE FOR ALL TO app_role
            USING (current_setting('app.user_role', TRUE) = 'director')
            WITH CHECK (current_setting('app.user_role', TRUE) = 'director')
        SQL);

        // Distributor manages commissions for their zone sellers
        DB::statement(<<<'SQL'
            CREATE POLICY scc_distributor_own ON seller_commissions_config
            AS PERMISSIVE FOR ALL TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
            )
        SQL);

        // Seller can see what % they are being paid
        DB::statement(<<<'SQL'
            CREATE POLICY scc_seller_read_own ON seller_commissions_config
            AS PERMISSIVE FOR SELECT TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'seller'
                AND seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY scc_report_read ON seller_commissions_config
            AS PERMISSIVE FOR SELECT TO report_role USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY scc_worker_read ON seller_commissions_config
            AS PERMISSIVE FOR SELECT TO worker_role USING (TRUE)
        SQL);

        // ================================================================
        // distributor_commission_payments
        // ================================================================
        DB::statement('ALTER TABLE distributor_commission_payments ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE distributor_commission_payments FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY dcp_director_all ON distributor_commission_payments
            AS PERMISSIVE FOR ALL TO app_role
            USING (current_setting('app.user_role', TRUE) = 'director')
            WITH CHECK (current_setting('app.user_role', TRUE) = 'director')
        SQL);

        // Distributor (payer): full access to their commission payment rows
        DB::statement(<<<'SQL'
            CREATE POLICY dcp_distributor_own ON distributor_commission_payments
            AS PERMISSIVE FOR ALL TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
            )
        SQL);

        // Seller (payee): can see commissions owed to them — read only
        DB::statement(<<<'SQL'
            CREATE POLICY dcp_seller_payee ON distributor_commission_payments
            AS PERMISSIVE FOR SELECT TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'seller'
                AND seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY dcp_report_read ON distributor_commission_payments
            AS PERMISSIVE FOR SELECT TO report_role USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY dcp_worker_read ON distributor_commission_payments
            AS PERMISSIVE FOR SELECT TO worker_role USING (TRUE)
        SQL);
    }

    public function down(): void
    {
        // distributor_commission_payments
        foreach (['dcp_director_all', 'dcp_distributor_own', 'dcp_seller_payee', 'dcp_report_read', 'dcp_worker_read'] as $p) {
            DB::statement("DROP POLICY IF EXISTS {$p} ON distributor_commission_payments");
        }
        DB::statement('ALTER TABLE distributor_commission_payments DISABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE distributor_commission_payments NO FORCE ROW LEVEL SECURITY');

        // seller_commissions_config
        foreach (['scc_director_all', 'scc_distributor_own', 'scc_seller_read_own', 'scc_report_read', 'scc_worker_read'] as $p) {
            DB::statement("DROP POLICY IF EXISTS {$p} ON seller_commissions_config");
        }
        DB::statement('ALTER TABLE seller_commissions_config DISABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE seller_commissions_config NO FORCE ROW LEVEL SECURITY');

        // distributor_settlements
        foreach (['ds_director_all', 'ds_distributor_own', 'ds_report_read', 'ds_worker_read'] as $p) {
            DB::statement("DROP POLICY IF EXISTS {$p} ON distributor_settlements");
        }
        DB::statement('ALTER TABLE distributor_settlements DISABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE distributor_settlements NO FORCE ROW LEVEL SECURITY');

        // distributor_account
        foreach (['da_director_all', 'da_distributor_own', 'da_report_read', 'da_worker_read'] as $p) {
            DB::statement("DROP POLICY IF EXISTS {$p} ON distributor_account");
        }
        DB::statement('ALTER TABLE distributor_account DISABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE distributor_account NO FORCE ROW LEVEL SECURITY');

        // distributor_preferred_cost
        foreach (['dpc_director_all', 'dpc_distributor_own', 'dpc_report_read', 'dpc_worker_read'] as $p) {
            DB::statement("DROP POLICY IF EXISTS {$p} ON distributor_preferred_cost");
        }
        DB::statement('ALTER TABLE distributor_preferred_cost DISABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE distributor_preferred_cost NO FORCE ROW LEVEL SECURITY');
    }
};
