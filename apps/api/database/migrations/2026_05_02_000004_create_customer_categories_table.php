<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the customer_categories table.
 *
 * Categories A–D are seeded via CustomerCategorySeeder, NOT here.
 * Seeding in migrations creates coupling between schema and data and makes
 * rollback/re-seed workflows fragile. Run the seeder separately:
 *
 *   php artisan db:seed --class=CustomerCategorySeeder
 *
 * Category D (Distribuidor-cliente) has director_only = TRUE:
 * only Directors can see or create clients in that category (§3.3).
 *
 * A category cannot be hard-deleted if it has active clients — the application
 * layer enforces this; the schema uses is_active for soft-deactivation.
 *
 * default_frequency_days drives the purchase evolution engine (§10).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE customer_categories (
                id                      UUID     PRIMARY KEY DEFAULT gen_random_uuid(),
                code                    CHAR(1)  NOT NULL,
                name                    TEXT     NOT NULL,
                default_frequency_days  INTEGER  NOT NULL,
                director_only           BOOLEAN  NOT NULL DEFAULT FALSE,
                is_active               BOOLEAN  NOT NULL DEFAULT TRUE,
                created_at              TIMESTAMPTZ NULL,
                updated_at              TIMESTAMPTZ NULL,

                CONSTRAINT uq_customer_categories_code UNIQUE (code)
            )
        SQL);

        // Role grants
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON customer_categories TO app_role');
        DB::statement('GRANT SELECT ON customer_categories TO report_role');
        DB::statement('GRANT SELECT ON customer_categories TO worker_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS customer_categories CASCADE');
    }
};
