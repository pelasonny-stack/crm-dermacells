<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 10 — purchase_evolution_metrics table.
 *
 * Stores the nightly-computed purchase evolution state for each
 * (customer, product) pair. Populated exclusively by EvolutionEngine::recompute()
 * via UPSERT from the mv_customer_product_purchases materialized view.
 *
 * NOT auditable — this is derived/computed state, not business mutations.
 * NOT append-only — rows are upserted on each nightly run.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS purchase_evolution_metrics (
                id                  UUID        NOT NULL DEFAULT gen_random_uuid(),
                customer_id         UUID        NOT NULL REFERENCES customers(id)  ON DELETE CASCADE,
                product_id          UUID        NOT NULL REFERENCES products(id)   ON DELETE CASCADE,
                last_purchase_date  DATE        NULL,
                avg_interval_days   NUMERIC(8,2) NULL,
                last_interval_days  INTEGER     NULL,
                purchase_count      INTEGER     NOT NULL DEFAULT 0,
                evolution_state     TEXT        NOT NULL CHECK (evolution_state IN (
                                        'first_purchase',
                                        'increasing',
                                        'stable',
                                        'decreasing',
                                        'scheduled',
                                        'inactive'
                                    )),
                computed_at         TIMESTAMPTZ NOT NULL,
                CONSTRAINT purchase_evolution_metrics_pkey PRIMARY KEY (id),
                CONSTRAINT purchase_evolution_metrics_customer_product_unique
                    UNIQUE (customer_id, product_id)
            )
        SQL);

        // Partial index on state for alert-scan jobs
        DB::statement('CREATE INDEX IF NOT EXISTS idx_pem_evolution_state ON purchase_evolution_metrics (evolution_state)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_pem_computed_at    ON purchase_evolution_metrics (computed_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_pem_customer_id    ON purchase_evolution_metrics (customer_id)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS purchase_evolution_metrics CASCADE');
    }
};
