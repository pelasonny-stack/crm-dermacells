<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Patches the users table RLS to allow all authenticated roles to SELECT
 * rows belonging to active Directors.
 *
 * ROOT CAUSE
 * ==========
 * The existing `users_self_read` policy only permits authenticated users to
 * SELECT their own row (`id = app.user_id`). When the AuthorizationService
 * fans-out notifications to all Directors after a Seller/Distributor submits
 * a request, the `User::query()->where('role', 'director')` call runs under
 * the requester's GUC and returns zero rows. No notifications are delivered.
 *
 * IMPACT
 * ======
 * - BroadcastToDirectorsTest: "sends AuthorizationRequestedNotification" fails.
 * - EvalZoneAtRiskAlertJob: queries Directors to target alerts — same gap if
 *   called under a non-director GUC context in future scenarios.
 *
 * FIX
 * ===
 * Add a permissive SELECT policy that allows any role to read rows for users
 * with role='director'. Directors are effectively public within the CRM — every
 * Seller knows who manages them, and notification routing requires this.
 *
 * This does NOT allow reading other Sellers' or Distributors' rows — only
 * Director rows are newly readable by all roles.
 *
 * The existing `users_self_read` policy (own-row SELECT for non-directors)
 * and `users_director_all` (full access for directors) are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE POLICY users_directors_readable ON users
            AS PERMISSIVE FOR SELECT
            TO app_role
            USING (
                role = 'director'
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS users_directors_readable ON users');
    }
};
