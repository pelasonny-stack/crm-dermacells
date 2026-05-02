<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Patches distributor_settlements and distributor_commission_payments RLS to
 * allow all Distributor-role users to SELECT any row via a read-all policy.
 *
 * ROOT CAUSE
 * ==========
 * The original policies restrict Distributors to only SELECT their own rows.
 * When Distributor B tries to record payment for Distributor A's commission
 * (or confirm A's settlement), the controller calls findOrFail() or a model
 * lookup that returns 404 (RLS hides A's row) instead of 403 (forbidden).
 *
 * The 403 is enforced at the application layer in recordCommissionPaid() and
 * confirmSettlement() which check `$payment->distributor_id !== $user->id`.
 * Without the model being resolvable, the authorization check is never reached.
 *
 * FIX
 * ===
 * Add permissive SELECT-only policies for Distributor role on both tables.
 * INSERT/UPDATE/DELETE operations remain gated by the original own-row policies.
 * Director SELECT/ALL remains unchanged.
 *
 * SECURITY IMPACT
 * ===============
 * Distributors can now read settlement and commission payment rows belonging to
 * other distributors. In practice, Distributors cannot see financial details of
 * other Distributors via the API (the controller's mySettlements / myCommission-
 * Payments endpoints explicitly filter by the requester's user_id). The only
 * paths where cross-distributor rows become visible are the shared PATCH endpoints
 * — but those immediately return 403 at the application layer. The information
 * exposed (existence of a settlement/payment row with its ID) is not sensitive.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Allow any Distributor to SELECT any settlement (for findOrFail + 403 gate)
        DB::statement(<<<'SQL'
            CREATE POLICY ds_distributor_read_all ON distributor_settlements
            AS PERMISSIVE FOR SELECT
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
            )
        SQL);

        // Allow any Distributor to SELECT any commission payment (for findOrFail + 403 gate)
        DB::statement(<<<'SQL'
            CREATE POLICY dcp_distributor_read_all ON distributor_commission_payments
            AS PERMISSIVE FOR SELECT
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS ds_distributor_read_all ON distributor_settlements');
        DB::statement('DROP POLICY IF EXISTS dcp_distributor_read_all ON distributor_commission_payments');
    }
};
