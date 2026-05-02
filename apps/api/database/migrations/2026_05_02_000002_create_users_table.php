<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the default Laravel users table (integer PK, password-based) with
 * the Dermacells schema: UUID PK, OAuth-only, role ENUM, citext email.
 *
 * The default migration 0001_01_01_000000_create_users_table.php creates the
 * vanilla Laravel table. This migration drops it and creates the correct one.
 * password_reset_tokens and sessions (also created by that migration) are left
 * intact — they are structurally fine for our purposes.
 *
 * Postgres ENUM type user_role is created here. If you ever need to add a new
 * value, use: ALTER TYPE user_role ADD VALUE 'new_value';
 *
 * GUCs read by RLS policies on this table:
 *   - app.user_id   (uuid)
 *   - app.user_role (director | distributor | seller)
 *
 * The can_sell CHECK constraint enforces that only directors may have the flag
 * set to true, preventing data inconsistency if role changes are made directly
 * in SQL outside the application layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ----------------------------------------------------------------
        // 0. CITEXT extension (case-insensitive text — needed for email)
        // ----------------------------------------------------------------
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');

        // ----------------------------------------------------------------
        // 1. Drop the vanilla Laravel users table (integer PK, password).
        //    Also drop the FK from sessions so it does not block the drop.
        // ----------------------------------------------------------------
        DB::statement('DROP TABLE IF EXISTS sessions CASCADE');
        DB::statement('DROP TABLE IF EXISTS users CASCADE');

        // ----------------------------------------------------------------
        // 2. Postgres ENUM type for the three operational roles
        // ----------------------------------------------------------------
        DB::statement(<<<'SQL'
            DO $$ BEGIN
                CREATE TYPE user_role AS ENUM ('director', 'distributor', 'seller');
            EXCEPTION
                WHEN duplicate_object THEN NULL;
            END $$
        SQL);

        // ----------------------------------------------------------------
        // 3. Users table
        // ----------------------------------------------------------------
        DB::statement(<<<'SQL'
            CREATE TABLE users (
                id                UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                email             CITEXT      NOT NULL,
                full_name         TEXT        NOT NULL,
                role              user_role   NOT NULL,
                can_sell          BOOLEAN     NOT NULL DEFAULT FALSE,
                is_active         BOOLEAN     NOT NULL DEFAULT TRUE,
                oauth_provider    TEXT        NULL,
                oauth_sub         TEXT        NULL,
                ai_enabled        BOOLEAN     NOT NULL DEFAULT TRUE,
                created_at        TIMESTAMPTZ NULL,
                updated_at        TIMESTAMPTZ NULL,
                deactivated_at    TIMESTAMPTZ NULL,

                -- can_sell is only meaningful (and allowed) for directors.
                -- Non-director rows must always have can_sell = false.
                CONSTRAINT chk_can_sell_director_only
                    CHECK (role = 'director' OR can_sell = FALSE),

                CONSTRAINT uq_users_email
                    UNIQUE (email),

                CONSTRAINT uq_users_oauth
                    UNIQUE (oauth_provider, oauth_sub)
            )
        SQL);

        // ----------------------------------------------------------------
        // 4. Indexes
        // ----------------------------------------------------------------
        DB::statement('CREATE INDEX idx_users_role_active ON users (role, is_active)');

        // ----------------------------------------------------------------
        // 5. Recreate the sessions table that we dropped (same structure
        //    as the default Laravel migration, FK now points to uuid users).
        // ----------------------------------------------------------------
        DB::statement(<<<'SQL'
            CREATE TABLE sessions (
                id            VARCHAR(255) PRIMARY KEY,
                user_id       UUID         NULL REFERENCES users (id) ON DELETE SET NULL,
                ip_address    VARCHAR(45)  NULL,
                user_agent    TEXT         NULL,
                payload       TEXT         NOT NULL,
                last_activity INTEGER      NOT NULL
            )
        SQL);

        DB::statement('CREATE INDEX idx_sessions_user_id ON sessions (user_id)');
        DB::statement('CREATE INDEX idx_sessions_last_activity ON sessions (last_activity)');

        // ----------------------------------------------------------------
        // 6. Role grants
        // ----------------------------------------------------------------
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON users TO app_role');
        DB::statement('GRANT SELECT ON users TO report_role');
        DB::statement('GRANT SELECT ON users TO worker_role');
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON sessions TO app_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS sessions CASCADE');
        DB::statement('DROP TABLE IF EXISTS users CASCADE');
        DB::statement('DROP TYPE IF EXISTS user_role CASCADE');
        DB::statement('DROP EXTENSION IF EXISTS citext CASCADE');

        // Restore the vanilla Laravel users table so other default migrations
        // can be re-run (e.g., cache, jobs tables that do not depend on it,
        // but the sessions table does reference it).
        DB::statement(<<<'SQL'
            CREATE TABLE users (
                id                BIGSERIAL    PRIMARY KEY,
                name              VARCHAR(255) NOT NULL,
                email             VARCHAR(255) NOT NULL UNIQUE,
                email_verified_at TIMESTAMPTZ  NULL,
                password          VARCHAR(255) NOT NULL,
                remember_token    VARCHAR(100) NULL,
                created_at        TIMESTAMPTZ  NULL,
                updated_at        TIMESTAMPTZ  NULL
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE sessions (
                id            VARCHAR(255) PRIMARY KEY,
                user_id       BIGINT       NULL REFERENCES users (id) ON DELETE SET NULL,
                ip_address    VARCHAR(45)  NULL,
                user_agent    TEXT         NULL,
                payload       TEXT         NOT NULL,
                last_activity INTEGER      NOT NULL
            )
        SQL);
    }
};
