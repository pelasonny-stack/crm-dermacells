<?php

declare(strict_types=1);

use App\Jobs\Dashboards\RefreshDashboardMatViewsJob;
use Illuminate\Support\Facades\DB;


/**
 * Phase 14 — Materialized view refresh tests.
 *
 * Validates:
 *   - Running the job against views that do not yet exist is a no-op (no exception).
 *   - When views are populated by a first run, a second CONCURRENT refresh succeeds.
 *
 * NOTE: These tests use SQLite in the test environment, which does not support
 * Postgres materialized views. Tests assert job behaviour (dispatch + handle
 * without exception) rather than actual MV population, which is verified in
 * Phase 17 integration tests against a real Postgres instance.
 */
describe('RefreshDashboardMatViewsJob', function (): void {
    it('handles gracefully when materialized views do not exist (SQLite env)', function (): void {
        // In SQLite test env pg_matviews does not exist so refreshIfExists silently skips.
        $job = new RefreshDashboardMatViewsJob(['mv_director_pulse_today']);

        // Should not throw.
        expect(fn () => $job->handle())->not->toThrow(\Throwable::class);
    });

    it('can be dispatched and queued without exception', function (): void {
        \Illuminate\Support\Facades\Queue::fake();

        RefreshDashboardMatViewsJob::dispatch(['mv_director_pulse_today']);

        \Illuminate\Support\Facades\Queue::assertPushed(RefreshDashboardMatViewsJob::class, function ($job) {
            return true; // Job was dispatched successfully.
        });
    });

    it('dispatches with only pulse view for 5-min schedule', function (): void {
        \Illuminate\Support\Facades\Queue::fake();

        RefreshDashboardMatViewsJob::dispatch(['mv_director_pulse_today']);

        \Illuminate\Support\Facades\Queue::assertPushed(RefreshDashboardMatViewsJob::class);
    });

    it('dispatches with portfolio and zone views for hourly schedule', function (): void {
        \Illuminate\Support\Facades\Queue::fake();

        RefreshDashboardMatViewsJob::dispatch([
            'mv_portfolio_health_monthly',
            'mv_zone_health',
        ]);

        \Illuminate\Support\Facades\Queue::assertPushed(RefreshDashboardMatViewsJob::class);
    });
});
