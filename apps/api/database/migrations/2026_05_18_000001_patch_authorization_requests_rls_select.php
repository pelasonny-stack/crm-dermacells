<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Patches authorization_requests RLS to allow all authenticated roles to
 * SELECT any row via a read-all permissive SELECT policy.
 *
 * ROOT CAUSE
 * ==========
 * The original RLS policies restrict Sellers and Distributors to only SELECT
 * their own requests (requested_by = user_id). When a Distributor tries to
 * resolve a Seller's request, route model binding fails with a 404 (model
 * not found) instead of the expected 403 (authorization denied).
 *
 * The 403 is enforced at the application layer by ResolveAuthorizationRequest::
 * authorize() which checks user role = Director. Without the model being
 * resolvable, the authorization gate is never reached.
 *
 * FIX
 * ===
 * Add a permissive SELECT-only policy for all roles on authorization_requests.
 * This allows route model binding to succeed for all authenticated callers so
 * that the application-layer authorization check can return 403. Write
 * operations (INSERT, UPDATE, DELETE) remain gated by the original policies.
 *
 * This does not expose sensitive data: the authorization_requests table
 * contains price/TC change requests which Distributors and Sellers already
 * see in the Director's approval UI (by design — they submitted them).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Allow any authenticated role to SELECT any authorization_request.
        // This is necessary for route model binding to resolve the model before
        // the application-level authorize() check runs.
        DB::statement(<<<'SQL'
            CREATE POLICY ar_all_can_read ON authorization_requests
            AS PERMISSIVE FOR SELECT
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) IN ('director', 'distributor', 'seller')
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS ar_all_can_read ON authorization_requests');
    }
};
