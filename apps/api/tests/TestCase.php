<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

/**
 * Base test case for CRM Dermacells.
 *
 * Test connection model:
 * - default connection 'pgsql_test' uses app_role (no BYPASSRLS) so RLS
 *   policies actually fire — without this, tests would silently mask
 *   cross-role denial bugs.
 * - The test DB schema is migrated externally (see scripts/setup-local.sh
 *   and the CI workflow), so DatabaseTransactions is used in Pest.php
 *   instead of RefreshDatabase.
 *
 * RLS bootstrap pattern:
 * - DatabaseTransactions opens a wrapping transaction around each test.
 * - In setUp() we SET LOCAL the GUCs to director scope so factory inserts
 *   pass the WITH CHECK predicates. Tests that need to verify per-role
 *   isolation open nested DB::transaction blocks (savepoints) and override
 *   the GUCs locally.
 *
 * SET LOCAL values revert when the wrapping transaction rolls back, which
 * is exactly the per-test isolation guarantee we want.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Connections DatabaseTransactions wraps in a per-test transaction.
     *
     * @var list<string>
     */
    protected $connectionsToTransact = ['pgsql_test'];

    protected function setUp(): void
    {
        parent::setUp();

        // Default test scope: director. Factory inserts and seeded reads
        // succeed without each test having to SET LOCAL itself. Tests that
        // exercise role-restricted views override via DB::transaction blocks.
        DB::statement("SET LOCAL app.user_role = 'director'");
        DB::statement("SET LOCAL app.user_id = '00000000-0000-0000-0000-000000000000'");
    }
}
