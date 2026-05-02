<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13 — model_pricing table.
 *
 * Versioned price list per (provider, model). The active row at the time
 * of an LLM call is selected by the largest `effective_from` <= today.
 * Multiple rows for the same (provider, model) over time form a price
 * history that allows historical usage to be repriced after the fact if
 * needed.
 *
 * input_per_1k       — base input price per 1k tokens.
 * cached_input_per_1k — discounted price for prompt-cache hits (Anthropic
 *                      ephemeral cache reads, OpenAI auto-cached prefix).
 *                      NULL = same price as `input_per_1k`.
 * output_per_1k      — completion / output price per 1k tokens.
 *
 * UNIQUE (provider, model, effective_from) prevents duplicate price
 * declarations for the same activation date.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE model_pricing (
                id                      UUID            PRIMARY KEY DEFAULT gen_random_uuid(),
                provider                TEXT            NOT NULL,
                model                   TEXT            NOT NULL,
                input_per_1k            NUMERIC(10,6)   NOT NULL,
                cached_input_per_1k     NUMERIC(10,6)   NULL,
                output_per_1k           NUMERIC(10,6)   NOT NULL,
                currency                CHAR(3)         NOT NULL DEFAULT 'USD',
                effective_from          DATE            NOT NULL,
                created_at              TIMESTAMPTZ     NULL,
                updated_at              TIMESTAMPTZ     NULL,

                CONSTRAINT uq_model_pricing_provider_model_date
                    UNIQUE (provider, model, effective_from),

                CONSTRAINT chk_model_pricing_positive
                    CHECK (input_per_1k >= 0 AND output_per_1k >= 0
                           AND (cached_input_per_1k IS NULL OR cached_input_per_1k >= 0)),

                CONSTRAINT chk_model_pricing_provider
                    CHECK (provider IN ('openai', 'anthropic'))
            )
        SQL);

        // Lookup: most recent active price for (provider, model).
        DB::statement('CREATE INDEX idx_model_pricing_lookup ON model_pricing (provider, model, effective_from DESC)');

        DB::statement('GRANT SELECT, INSERT, UPDATE ON model_pricing TO app_role');
        DB::statement('GRANT SELECT ON model_pricing TO worker_role');
        DB::statement('GRANT SELECT ON model_pricing TO report_role');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS model_pricing CASCADE');
    }
};
