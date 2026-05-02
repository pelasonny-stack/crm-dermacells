<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Patches the customer_account_balances RLS policies to allow Sellers and
 * Distributors to INSERT and UPDATE balance rows for their own customers.
 *
 * BACKGROUND
 * ==========
 * The original migration 2026_05_08_000005_enable_payments_rls.php granted only
 * SELECT to Sellers and Distributors on customer_account_balances. However, when
 * a Seller registers a payment the PaymentObserver fires AccountBalanceUpdater,
 * which issues an INSERT ON CONFLICT DO UPDATE to customer_account_balances.
 * Without write access the Seller's GUC fails the WITH CHECK predicate, raising
 * SQLSTATE 42501 (Insufficient privilege).
 *
 * FIX
 * ===
 * Drop the SELECT-only policies and replace them with ALL policies that also
 * include WITH CHECK guards so sellers can only write rows for their own
 * assigned customers and distributors can only write rows for customers in
 * their managed zones.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop the existing SELECT-only seller and distributor policies
        DB::statement('DROP POLICY IF EXISTS cab_seller_own ON customer_account_balances');
        DB::statement('DROP POLICY IF EXISTS cab_distributor_zone ON customer_account_balances');

        // Seller: SELECT + INSERT/UPDATE for own customers' account balances
        DB::statement(<<<'SQL'
            CREATE POLICY cab_seller_own ON customer_account_balances
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'seller'
                AND customer_id IN (
                    SELECT id FROM customers
                    WHERE assigned_seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'seller'
                AND customer_id IN (
                    SELECT id FROM customers
                    WHERE assigned_seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
                )
            )
        SQL);

        // Distributor: SELECT + INSERT/UPDATE for customers in their zone
        DB::statement(<<<'SQL'
            CREATE POLICY cab_distributor_zone ON customer_account_balances
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND customer_id IN (
                    SELECT id FROM customers
                    WHERE zone_id IN (
                        SELECT id FROM zones
                        WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
                    )
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND customer_id IN (
                    SELECT id FROM customers
                    WHERE zone_id IN (
                        SELECT id FROM zones
                        WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
                    )
                )
            )
        SQL);
    }

    public function down(): void
    {
        // Restore the original SELECT-only policies
        DB::statement('DROP POLICY IF EXISTS cab_seller_own ON customer_account_balances');
        DB::statement('DROP POLICY IF EXISTS cab_distributor_zone ON customer_account_balances');

        DB::statement(<<<'SQL'
            CREATE POLICY cab_seller_own ON customer_account_balances
            AS PERMISSIVE FOR SELECT
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'seller'
                AND customer_id IN (
                    SELECT id FROM customers
                    WHERE assigned_seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY cab_distributor_zone ON customer_account_balances
            AS PERMISSIVE FOR SELECT
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND customer_id IN (
                    SELECT id FROM customers
                    WHERE zone_id IN (
                        SELECT id FROM zones
                        WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
                    )
                )
            )
        SQL);
    }
};
