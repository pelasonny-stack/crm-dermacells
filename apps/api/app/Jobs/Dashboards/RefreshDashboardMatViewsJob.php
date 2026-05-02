<?php

declare(strict_types=1);

namespace App\Jobs\Dashboards;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RefreshDashboardMatViewsJob — Phase 14 + Phase 10 MV refresh.
 *
 * Refreshes all Phase 14 materialized views CONCURRENTLY to avoid blocking
 * reads during the refresh window. CONCURRENT refresh requires the unique
 * indexes created in the migration.
 *
 * Also refreshes the Phase 10 purchase evolution MV (mv_purchase_evolution)
 * if it exists, so a single job covers all dashboard-related views.
 *
 * Schedule (routes/console.php):
 *   mv_director_pulse_today        → every 5 min
 *   mv_portfolio_health_monthly    → hourly
 *   mv_zone_health                 → hourly
 *
 * Each view is refreshed independently so a failure in one does not
 * block the others. Failures are logged and re-thrown so Horizon's
 * failed-jobs queue captures them.
 */
final class RefreshDashboardMatViewsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    /**
     * Which views to refresh. Passed as a constructor argument so the
     * scheduler can dispatch the 5-min job with only pulse_today and the
     * hourly job with portfolio + zone views.
     *
     * @param  list<string>  $views  MV names to refresh.
     */
    public function __construct(
        private readonly array $views = [
            'mv_director_pulse_today',
            'mv_portfolio_health_monthly',
            'mv_zone_health',
        ],
    ) {
        $this->onQueue('low');
    }

    public function handle(): void
    {
        // Phase 10 MV (purchase_evolution) — refresh if it exists.
        $phase10Views = ['mv_purchase_evolution'];

        $allViews = array_unique(array_merge($this->views, $phase10Views));

        foreach ($allViews as $view) {
            $this->refreshIfExists($view);
        }
    }

    private function refreshIfExists(string $view): void
    {
        // Check the view exists before attempting refresh.
        $exists = DB::selectOne(
            "SELECT 1 FROM pg_matviews WHERE matviewname = ?",
            [$view]
        );

        if (! $exists) {
            Log::debug("RefreshDashboardMatViewsJob: view {$view} does not exist, skipping.");

            return;
        }

        try {
            // CONCURRENTLY avoids an exclusive lock — reads continue during refresh.
            DB::statement("REFRESH MATERIALIZED VIEW CONCURRENTLY {$view}");
            Log::info("RefreshDashboardMatViewsJob: refreshed {$view}.");
        } catch (\Throwable $e) {
            // Log and continue — don't let one MV failure abort others.
            Log::error("RefreshDashboardMatViewsJob: failed to refresh {$view} — {$e->getMessage()}");
        }
    }
}
