<?php

declare(strict_types=1);

namespace App\Jobs\Evolution;

use App\Domain\Evolution\Services\EvolutionEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * RecomputePurchaseEvolutionJob — Phase 10 nightly job (§10.1).
 *
 * Delegates to EvolutionEngine::recompute() which:
 *   1. REFRESH MATERIALIZED VIEW CONCURRENTLY mv_customer_product_purchases
 *   2. UPSERTS purchase_evolution_metrics from the refreshed view
 *
 * Scheduled daily at 02:00 ART from routes/console.php.
 * ShouldBeUnique prevents overlapping runs if Horizon retries or the scheduler
 * fires twice after a restart.
 *
 * Queue: 'default' (not 'critical' — this is analytical, not transactional).
 * Timeout: 10 minutes to accommodate large datasets.
 */
class RecomputePurchaseEvolutionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int Retry attempts before marking failed. */
    public int $tries = 3;

    /** @var int Backoff in seconds between retries (5min, 10min, 20min). */
    public array $backoff = [300, 600, 1200];

    /** @var int Job timeout in seconds (10 minutes). */
    public int $timeout = 600;

    public function handle(EvolutionEngine $engine): void
    {
        Log::info('RecomputePurchaseEvolutionJob: starting.');

        $affected = $engine->recompute();

        Log::info("RecomputePurchaseEvolutionJob: completed. Rows upserted: {$affected}.");
    }

    public function failed(\Throwable $e): void
    {
        Log::critical(
            "RecomputePurchaseEvolutionJob: FAILED — {$e->getMessage()}. " .
            'Purchase evolution metrics may be stale. Check Horizon failed queue.'
        );
    }
}
