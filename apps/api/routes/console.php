<?php

declare(strict_types=1);

use App\Console\Commands\DispatchBirthdayAlerts;
use App\Console\Commands\VerifyAuditChain;
use App\Jobs\Evolution\EvalCustomerInactiveAlertJob;
use App\Jobs\Evolution\EvalDecreasingFrequencyAlertJob;
use App\Jobs\Evolution\EvalFirstPurchaseNoReorderAlertJob;
use App\Jobs\Evolution\EvalNextCycleDueAlertJob;
use App\Jobs\Evolution\EvalZoneAtRiskAlertJob;
use App\Jobs\Evolution\RecomputePurchaseEvolutionJob;
use App\Jobs\FetchBcraExchangeRateJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes (Laravel 12 — replaces legacy Kernel.php::schedule())
|--------------------------------------------------------------------------
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Audit Log Partition Maintenance
|--------------------------------------------------------------------------
|
| Creates the next month's audit_log partition on the 1st of each month at
| 00:05 ART (America/Argentina/Buenos_Aires). Running at 00:05 instead of
| midnight avoids races with any month-boundary cron jobs.
|
| The command is idempotent — if the partition already exists, Postgres
| silently skips creation (CREATE TABLE IF NOT EXISTS).
|
| In production, ensure the artisan scheduler process connects as a role
| with DDL privileges (migration_role) by configuring the pgsql_migration
| connection with the appropriate credentials in .env:
|
|   DB_MIGRATION_USERNAME=migration_role
|   DB_MIGRATION_PASSWORD=<secret>
|
*/
Schedule::command('audit:create-next-partition')
    ->monthlyOn(1, '00:05')
    ->timezone('America/Argentina/Buenos_Aires')
    ->withoutOverlapping()
    ->runInBackground()
    ->onFailure(function () {
        // Log the failure so the ops team can create the partition manually.
        \Illuminate\Support\Facades\Log::critical(
            'audit:create-next-partition failed — audit_log may be missing next month\'s partition. ' .
            'Run manually: php artisan audit:create-next-partition'
        );
    });

/*
|--------------------------------------------------------------------------
| Audit Log HMAC Chain Verification
|--------------------------------------------------------------------------
|
| Per PLAN.md Phase 1.6 the chain integrity check runs nightly. A
| non-zero exit code is logged and surfaces via CloudWatch / Sentry.
|
| Run at 03:15 ART so it does not collide with the 19:00 BCRA fetch
| (Phase 2), the 00:05 partition creation above, or backups.
|
*/

/*
|--------------------------------------------------------------------------
| BCRA Exchange Rate Fetch (Phase 2 — §16.7)
|--------------------------------------------------------------------------
|
| Fetches the dólar vendedor BNA closing rate from the BCRA estadísticas
| cambiarias API each business day at 19:00 ART. The BCRA publishes the
| closing rate around 17:30-18:00 ART — running at 19:00 guarantees the
| rate is available.
|
| ShouldBeUnique on the job prevents double execution if the scheduler fires
| twice (e.g. after a restart). withoutOverlapping() adds a scheduler-level
| guard as a second layer.
|
| On BCRA API failure, FetchBcraExchangeRateJob automatically applies the
| fallback logic (§16.7): copies the most recent rate with source='fallback'
| and notifies all Directors via queued mail + Reverb broadcast.
|
*/
// Note: Schedule::job() creates a CallbackEvent which does not support
// runInBackground() — background execution is handled by the queue worker
// (the job implements ShouldQueue). The scheduler entry simply dispatches.
Schedule::job(FetchBcraExchangeRateJob::class)
    ->dailyAt('19:00')
    ->timezone('America/Argentina/Buenos_Aires')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::critical(
            'FetchBcraExchangeRateJob scheduler entry failed. Check failed jobs in Horizon.'
        );
    });

/*
|--------------------------------------------------------------------------
| Phase 3 — Birthday & Scheduled Action Alerts (§3.6, §3.8, §15)
|--------------------------------------------------------------------------
|
| Runs daily at 7:55 AM ART — 5 minutes before the 8:00 AM delivery target,
| accounting for scheduler jitter and notification pipeline latency.
|
| The command is idempotent: it checks the notifications table for a
| same-day entry before sending, so manual re-runs or scheduler restarts
| will not produce duplicate notifications.
|
*/
Schedule::command(DispatchBirthdayAlerts::class)
    ->dailyAt('07:55')
    ->timezone('America/Argentina/Buenos_Aires')
    ->onOneServer()
    ->withoutOverlapping()
    ->runInBackground()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::critical(
            'customers:dispatch-birthday-alerts failed. Birthday and scheduled-action push notifications may not have been sent.'
        );
    });

Schedule::command(VerifyAuditChain::class)
    ->daily()
    ->at('03:15')
    ->timezone('America/Argentina/Buenos_Aires')
    ->onOneServer()
    ->withoutOverlapping()
    ->runInBackground()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::critical(
            'audit:verify failed — possible audit_log tampering or HMAC key drift. ' .
            'Investigate immediately and freeze write traffic if confirmed.'
        );
    });

/*
|--------------------------------------------------------------------------
| Phase 4 — Stock Monitoring Jobs (§8.3, §15, §16.11)
|--------------------------------------------------------------------------
|
| MonitorStockMinimumsJob: hourly scan of seller_stock, distributor_stock,
|   and central_stock for available < minimum_stock. Dispatches
|   StockBelowMinimumNotification to Directors + Distributor of the zone.
|   Cache de-duplication prevents repeat alerts within 24h.
|
| LotExpiryAlertJob: daily at 06:30 ART; scans stock_lots for lots expiring
|   within config('stock.lot_expiry_alert_days', 30) days and notifies
|   Directors. Runs before the work day begins.
|
*/

// Note: Schedule::job() does not support runInBackground() — background
// execution is handled by the queue worker since the jobs implement ShouldQueue.
Schedule::job(\App\Jobs\MonitorStockMinimumsJob::class)
    ->hourly()
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error(
            'MonitorStockMinimumsJob failed — stock low-stock alerts may be delayed.'
        );
    });

Schedule::job(\App\Jobs\LotExpiryAlertJob::class)
    ->dailyAt('06:30')
    ->timezone('America/Argentina/Buenos_Aires')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error(
            'LotExpiryAlertJob failed — lot expiry alerts may be delayed.'
        );
    });

// Phase 10 — Evolution + Alertas (§10, §15)
// All times are ART (America/Argentina/Buenos_Aires).
// Order matters: RecomputePurchaseEvolutionJob runs at 02:00; all alert
// evaluation jobs run AFTER it (02:30+) to ensure they read fresh metric data.

Schedule::job(RecomputePurchaseEvolutionJob::class)
    ->dailyAt('02:00')
    ->timezone('America/Argentina/Buenos_Aires')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::critical(
            'RecomputePurchaseEvolutionJob failed — evolution metrics may be stale. ' .
            'Alert evaluation jobs at 02:30+ will operate on prior-night data.'
        );
    });

Schedule::job(EvalNextCycleDueAlertJob::class)
    ->dailyAt('02:30')
    ->timezone('America/Argentina/Buenos_Aires')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('EvalNextCycleDueAlertJob failed.');
    });

Schedule::job(EvalDecreasingFrequencyAlertJob::class)
    ->dailyAt('02:35')
    ->timezone('America/Argentina/Buenos_Aires')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('EvalDecreasingFrequencyAlertJob failed.');
    });

Schedule::job(EvalCustomerInactiveAlertJob::class)
    ->dailyAt('02:40')
    ->timezone('America/Argentina/Buenos_Aires')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('EvalCustomerInactiveAlertJob failed.');
    });

Schedule::job(EvalFirstPurchaseNoReorderAlertJob::class)
    ->dailyAt('02:45')
    ->timezone('America/Argentina/Buenos_Aires')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('EvalFirstPurchaseNoReorderAlertJob failed.');
    });

Schedule::job(EvalZoneAtRiskAlertJob::class)
    ->dailyAt('03:00')
    ->timezone('America/Argentina/Buenos_Aires')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('EvalZoneAtRiskAlertJob failed.');
    });

/*
|--------------------------------------------------------------------------
| Phase 8 — Monthly Commission Computation (§9.2)
|--------------------------------------------------------------------------
|
| Runs on the 1st of each month at 02:30 ART (after the audit partition job
| at 00:05 and before the evolution recompute at 02:00 — wait, commission
| job runs at 02:30 which is deliberately after evolution at 02:00, so
| commission data reflects fresh metrics for the month just ended).
|
| onOneServer() guarantees a single execution across multiple scheduler
| processes (Horizon workers). The job itself is idempotent via INSERT
| ... ON CONFLICT DO NOTHING on the UNIQUE (distributor_id, seller_id,
| zone_id, period_month) constraint.
|
*/
Schedule::job(\App\Jobs\DistributorFinance\ComputeMonthlyCommissionsJob::class)
    ->monthlyOn(1, '02:30')
    ->timezone('America/Argentina/Buenos_Aires')
    ->onOneServer()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::critical(
            'ComputeMonthlyCommissionsJob failed — monthly distributor-to-seller commissions may not have been computed. '
            . 'Dispatch manually: App\Jobs\DistributorFinance\ComputeMonthlyCommissionsJob::dispatch().'
        );
    });

/*
|--------------------------------------------------------------------------
| Phase 6 — Xubio Billing Maintenance (§6)
|--------------------------------------------------------------------------
|
| ReconcileInvoicesNightlyJob: 04:00 ART daily. Polls Xubio for any invoice
|   still in 'reconciling' or aged 'pending' status and tries to match by
|   external_ref. Failures roll up into a single Director alert.
|
| PruneXubioApiLogJob: daily. Deletes xubio_api_log rows older than 90d.
|
*/
Schedule::job(\App\Jobs\Xubio\ReconcileInvoicesNightlyJob::class)
    ->dailyAt('04:00')
    ->timezone('America/Argentina/Buenos_Aires')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error(
            'ReconcileInvoicesNightlyJob failed — Xubio reconciliation will retry next cycle.'
        );
    });

Schedule::job(\App\Jobs\Xubio\PruneXubioApiLogJob::class)
    ->daily()
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::warning(
            'PruneXubioApiLogJob failed — xubio_api_log retention may exceed 90d.'
        );
    });

/*
|--------------------------------------------------------------------------
| Phase 14 — Dashboard Materialized View Refresh (§14)
|--------------------------------------------------------------------------
|
| mv_director_pulse_today is refreshed every 5 minutes because it shows
| the live business pulse (today's deliveries and payments).
|
| mv_portfolio_health_monthly and mv_zone_health are refreshed hourly.
| They aggregate 12 months of data — 5-minute refresh would be excessive.
|
| Both jobs dispatch only the relevant subset of views via the $views
| constructor argument so a single job class covers all scenarios.
|
*/
Schedule::job(new \App\Jobs\Dashboards\RefreshDashboardMatViewsJob(['mv_director_pulse_today']))
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error(
            'RefreshDashboardMatViewsJob (pulse) failed — Director pulse dashboard may be stale.'
        );
    });

Schedule::job(new \App\Jobs\Dashboards\RefreshDashboardMatViewsJob([
    'mv_portfolio_health_monthly',
    'mv_zone_health',
]))
    ->hourly()
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error(
            'RefreshDashboardMatViewsJob (hourly) failed — portfolio health dashboard may be stale.'
        );
    });

/*
|--------------------------------------------------------------------------
| Phase 13 — AI Daily Digest Pre-warm (§11.2)
|--------------------------------------------------------------------------
|
| At 07:00 ART (one hour before the field day starts) we pre-compute every
| active seller's daily digest into the cache so the first dashboard load
| is free (no LLM call on the user's request path).
|
| The job exits cheaply when the AI module is globally disabled.
*/
Schedule::job(\App\Jobs\AI\GenerateDailyDigestForAllSellers::class)
    ->dailyAt('07:00')
    ->timezone('America/Argentina/Buenos_Aires')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error(
            'GenerateDailyDigestForAllSellers failed — daily digests may be missing for some users.'
        );
    });
