<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13 — ai_usage table (§11.5 token monitor).
 *
 * One row per LLM call. RecordAiUsage queueable job inserts after each
 * successful chat()/stream() completion. customer_id is nullable for
 * non-customer-scoped calls (e.g. natural language queries that never
 * inject a single-customer context).
 *
 * `period_month` is denormalised to the first day of the call's month so
 * monthly aggregation can use a btree range scan on (user_id, period_month)
 * instead of date_trunc on created_at, which would not be sargable.
 *
 * total_tokens is a Postgres GENERATED column — always input + output, no
 * application bug can ever drift the sum.
 *
 * cost_estimate_usd is precomputed on insert against the active row in
 * model_pricing for that (provider, model). Storing it avoids repricing
 * historical usage when the price list updates.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE ai_usage (
                id                      BIGSERIAL       PRIMARY KEY,
                user_id                 UUID            NOT NULL
                                        REFERENCES users (id) ON DELETE RESTRICT,
                customer_id             UUID            NULL
                                        REFERENCES customers (id) ON DELETE SET NULL,
                period_month            DATE            NOT NULL,
                provider                TEXT            NOT NULL,
                model                   TEXT            NOT NULL,
                input_tokens            INTEGER         NOT NULL DEFAULT 0,
                cached_input_tokens     INTEGER         NOT NULL DEFAULT 0,
                output_tokens           INTEGER         NOT NULL DEFAULT 0,
                total_tokens            INTEGER         GENERATED ALWAYS AS (input_tokens + output_tokens) STORED,
                cost_estimate_usd       NUMERIC(10,4)   NOT NULL DEFAULT 0,
                request_id              UUID            NULL,
                latency_ms              INTEGER         NULL,
                created_at              TIMESTAMPTZ     NOT NULL DEFAULT now(),

                CONSTRAINT chk_ai_usage_tokens_non_negative
                    CHECK (input_tokens >= 0 AND output_tokens >= 0 AND cached_input_tokens >= 0),

                CONSTRAINT chk_ai_usage_cost_non_negative
                    CHECK (cost_estimate_usd >= 0)
            )
        SQL);

        // Hot path: cap-check middleware sums by (user_id, period_month) on every request.
        DB::statement('CREATE INDEX idx_ai_usage_user_period ON ai_usage (user_id, period_month)');
        DB::statement('CREATE INDEX idx_ai_usage_customer ON ai_usage (customer_id) WHERE customer_id IS NOT NULL');
        DB::statement('CREATE INDEX idx_ai_usage_created_at ON ai_usage (created_at DESC)');

        DB::statement('GRANT SELECT, INSERT ON ai_usage TO app_role');
        DB::statement('GRANT SELECT, INSERT ON ai_usage TO worker_role');
        DB::statement('GRANT SELECT ON ai_usage TO report_role');
        DB::statement('GRANT USAGE, SELECT ON SEQUENCE ai_usage_id_seq TO app_role');
        DB::statement('GRANT USAGE, SELECT ON SEQUENCE ai_usage_id_seq TO worker_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS ai_usage CASCADE');
    }
};
