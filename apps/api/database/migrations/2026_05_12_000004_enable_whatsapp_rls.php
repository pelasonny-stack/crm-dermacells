<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row Level Security policies for Phase 12 WhatsApp tables.
 *
 * POLICY MATRIX
 * =============
 *
 * whatsapp_threads:
 *   Director    → sees ALL threads (no restriction)
 *   Distributor → sees threads whose customer is in one of their zones
 *   Seller      → sees threads whose customer is assigned to them
 *
 * whatsapp_messages:
 *   All access is funnelled through whatsapp_threads (FK thread_id).
 *   The policy mirrors the threads policy via a sub-select so RLS is
 *   enforced at the message level too (defense-in-depth).
 *
 * unmatched_whatsapp_messages:
 *   Director-only at the application layer (Filament admin gate).
 *   RLS is not enabled on this table — it never surfaces in the Seller/
 *   Distributor API and is behind the AdminAccessGate middleware.
 *
 * GUCs used (set by SetPostgresRlsContext per-transaction):
 *   app.user_id   UUID of the authenticated user
 *   app.user_role 'director' | 'distributor' | 'seller'
 */
return new class extends Migration
{
    public function up(): void
    {
        // ================================================================
        // whatsapp_threads
        // ================================================================
        DB::statement('ALTER TABLE whatsapp_threads ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE whatsapp_threads FORCE ROW LEVEL SECURITY');

        // Director: unrestricted access
        DB::statement(<<<'SQL'
            CREATE POLICY wa_threads_director_all ON whatsapp_threads
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'director'
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'director'
            )
        SQL);

        // Distributor: threads belonging to customers in their zone(s)
        DB::statement(<<<'SQL'
            CREATE POLICY wa_threads_distributor_zone ON whatsapp_threads
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND (
                    customer_id IS NULL
                    OR customer_id IN (
                        SELECT id FROM customers
                        WHERE zone_id IN (
                            SELECT id FROM zones
                            WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
                        )
                    )
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND (
                    customer_id IS NULL
                    OR customer_id IN (
                        SELECT id FROM customers
                        WHERE zone_id IN (
                            SELECT id FROM zones
                            WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
                        )
                    )
                )
            )
        SQL);

        // Seller: threads belonging to their assigned customers only
        DB::statement(<<<'SQL'
            CREATE POLICY wa_threads_seller_assigned ON whatsapp_threads
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

        DB::statement(<<<'SQL'
            CREATE POLICY wa_threads_report_read ON whatsapp_threads
            AS PERMISSIVE FOR SELECT
            TO report_role
            USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY wa_threads_worker_read ON whatsapp_threads
            AS PERMISSIVE FOR SELECT
            TO worker_role
            USING (TRUE)
        SQL);

        // ================================================================
        // whatsapp_messages
        // ================================================================
        DB::statement('ALTER TABLE whatsapp_messages ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE whatsapp_messages FORCE ROW LEVEL SECURITY');

        // Director: all messages
        DB::statement(<<<'SQL'
            CREATE POLICY wa_messages_director_all ON whatsapp_messages
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'director'
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'director'
            )
        SQL);

        // Distributor: messages belonging to threads in their zone(s)
        DB::statement(<<<'SQL'
            CREATE POLICY wa_messages_distributor_zone ON whatsapp_messages
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND thread_id IN (
                    SELECT id FROM whatsapp_threads
                    WHERE customer_id IN (
                        SELECT id FROM customers
                        WHERE zone_id IN (
                            SELECT id FROM zones
                            WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
                        )
                    )
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'distributor'
                AND thread_id IN (
                    SELECT id FROM whatsapp_threads
                    WHERE customer_id IN (
                        SELECT id FROM customers
                        WHERE zone_id IN (
                            SELECT id FROM zones
                            WHERE distributor_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
                        )
                    )
                )
            )
        SQL);

        // Seller: messages in threads assigned to their customers
        DB::statement(<<<'SQL'
            CREATE POLICY wa_messages_seller_assigned ON whatsapp_messages
            AS PERMISSIVE FOR ALL
            TO app_role
            USING (
                current_setting('app.user_role', TRUE) = 'seller'
                AND thread_id IN (
                    SELECT id FROM whatsapp_threads
                    WHERE customer_id IN (
                        SELECT id FROM customers
                        WHERE assigned_seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
                    )
                )
            )
            WITH CHECK (
                current_setting('app.user_role', TRUE) = 'seller'
                AND thread_id IN (
                    SELECT id FROM whatsapp_threads
                    WHERE customer_id IN (
                        SELECT id FROM customers
                        WHERE assigned_seller_id = NULLIF(current_setting('app.user_id', TRUE), '')::uuid
                    )
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY wa_messages_report_read ON whatsapp_messages
            AS PERMISSIVE FOR SELECT
            TO report_role
            USING (TRUE)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY wa_messages_worker_read ON whatsapp_messages
            AS PERMISSIVE FOR SELECT
            TO worker_role
            USING (TRUE)
        SQL);
    }

    public function down(): void
    {
        // whatsapp_messages
        foreach ([
            'wa_messages_director_all',
            'wa_messages_distributor_zone',
            'wa_messages_seller_assigned',
            'wa_messages_report_read',
            'wa_messages_worker_read',
        ] as $policy) {
            DB::statement("DROP POLICY IF EXISTS {$policy} ON whatsapp_messages");
        }
        DB::statement('ALTER TABLE whatsapp_messages DISABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE whatsapp_messages NO FORCE ROW LEVEL SECURITY');

        // whatsapp_threads
        foreach ([
            'wa_threads_director_all',
            'wa_threads_distributor_zone',
            'wa_threads_seller_assigned',
            'wa_threads_report_read',
            'wa_threads_worker_read',
        ] as $policy) {
            DB::statement("DROP POLICY IF EXISTS {$policy} ON whatsapp_threads");
        }
        DB::statement('ALTER TABLE whatsapp_threads DISABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE whatsapp_threads NO FORCE ROW LEVEL SECURITY');
    }
};
