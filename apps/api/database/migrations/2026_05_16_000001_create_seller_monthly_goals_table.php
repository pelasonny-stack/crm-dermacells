<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the seller_monthly_goals table — §12.1, §16.8.
 *
 * Metas mensuales por Vendedor configuradas por el Director.
 * UNIQUE (seller_id, year_month) garantiza una sola meta por Vendedor por mes.
 * year_month almacenado como primer día del mes (DATE) para simplicidad de queries.
 *
 * DB roles: app_role INSERT/SELECT/UPDATE/DELETE; report_role SELECT.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE seller_monthly_goals (
                id             UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                seller_id      UUID        NOT NULL
                               REFERENCES users (id) ON DELETE CASCADE,
                year_month     DATE        NOT NULL,
                target_boxes   INTEGER     NOT NULL CHECK (target_boxes >= 0),
                created_by     UUID        NOT NULL
                               REFERENCES users (id) ON DELETE RESTRICT,
                created_at     TIMESTAMPTZ NULL,
                updated_at     TIMESTAMPTZ NULL,

                CONSTRAINT uq_seller_monthly_goals_seller_month
                    UNIQUE (seller_id, year_month)
            )
        SQL);

        DB::statement('CREATE INDEX idx_smg_seller_id    ON seller_monthly_goals (seller_id)');
        DB::statement('CREATE INDEX idx_smg_year_month   ON seller_monthly_goals (year_month)');

        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON seller_monthly_goals TO app_role');
        DB::statement('GRANT SELECT ON seller_monthly_goals TO report_role');
        DB::statement('GRANT SELECT ON seller_monthly_goals TO worker_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS seller_monthly_goals CASCADE');
    }
};
