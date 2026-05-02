<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drops the ar_all_can_read policy on authorization_requests.
 *
 * The policy was added by 2026_05_18_000001 to fix route model binding
 * (allowing distributors to get 403 instead of 404 on resolve). However,
 * it broke RlsAuthorizationVisibilityTest which verifies that sellerA cannot
 * read sellerB's requests at the DB level.
 *
 * The OnlyDirectorCanResolve test was fixed at the test layer instead
 * (resetting GUC to director before calling fresh()). The ar_all_can_read
 * policy is not needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP POLICY IF EXISTS ar_all_can_read ON authorization_requests');
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            CREATE POLICY ar_all_can_read ON authorization_requests
            AS PERMISSIVE FOR SELECT
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) IN ('director', 'distributor', 'seller')
            )
        SQL);
    }
};
