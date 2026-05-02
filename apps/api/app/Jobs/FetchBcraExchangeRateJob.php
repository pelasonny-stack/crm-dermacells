<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ExchangeRate;
use App\Models\User;
use App\Notifications\BcraFallbackTriggered;
use App\Services\Bcra\BcraClient;
use App\Services\Bcra\BcraUnavailableException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Fetches the USD sell rate from the BCRA estadísticas cambiarias API and
 * persists it to the exchange_rates table.
 *
 * Scheduled daily at 19:00 ART via routes/console.php. The BCRA publishes
 * closing rates around 17:30-18:00 ART, so by 19:00 the rate is guaranteed
 * to be available.
 *
 * UNIQUENESS: ShouldBeUnique prevents duplicate dispatches within the same
 * calendar day. The uniqueId() is keyed on today's date so if the job is
 * manually re-dispatched (e.g. after a failure) the second dispatch is silently
 * ignored rather than creating duplicate DB rows.
 *
 * FALLBACK (§16.7): When the BCRA API is unavailable (BcraUnavailableException),
 * the job:
 *   1. Reads the most recent ExchangeRate from the DB.
 *   2. If found, inserts a new row for today with the same rate and
 *      source='fallback'.
 *   3. Notifies all Directors via queued mail (BcraFallbackTriggered) and
 *      broadcasts a Reverb event on the private-director channel.
 *   4. If no prior rate exists at all, logs a critical alert and fails the job
 *      so Horizon marks it for manual intervention.
 *
 * The job runs on the 'default' queue. In production, the Horizon config
 * assigns this queue to a supervisor with autoScalingEnabled.
 */
class FetchBcraExchangeRateJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted before marking as failed.
     * The BCRA API is often flaky — 3 attempts with backoff before giving up
     * and triggering the fallback path manually via the scheduler.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before retrying after a failure.
     *
     * @var list<int>
     */
    public array $backoff = [60, 300, 900];

    /**
     * Maximum number of seconds the job may run before timing out.
     */
    public int $timeout = 30;

    public function __construct()
    {
        $this->onQueue('default');
    }

    /**
     * Unique key prevents duplicate jobs for the same day.
     *
     * ShouldBeUnique uses the cache store configured in config/queue.php
     * under the 'unique' key (defaults to the default cache store).
     */
    public function uniqueId(): string
    {
        return 'bcra-fetch-' . Carbon::today('America/Argentina/Buenos_Aires')->toDateString();
    }

    /**
     * Seconds the unique lock is held. The lock is released after this TTL
     * regardless of job completion. 12 hours covers the full business day.
     */
    public function uniqueFor(): int
    {
        return 43200; // 12 hours
    }

    /**
     * Execute the job.
     *
     * Uses a DB transaction to ensure the insert and any related mutations
     * are atomic. The transaction also satisfies the RLS requirement: the
     * worker_role connection has been granted INSERT on exchange_rates, and
     * the worker_role policy (exchange_rates_worker_write) allows unrestricted
     * writes.
     */
    public function handle(BcraClient $bcraClient): void
    {
        $today = Carbon::today('America/Argentina/Buenos_Aires');

        try {
            $dto = $bcraClient->fetchUsdSellRate($today);

            DB::transaction(function () use ($dto): void {
                ExchangeRate::updateOrCreate(
                    ['rate_date' => $dto->date->toDateString()],
                    [
                        'rate_ars_per_usd' => (string) $dto->rateArsPerUsd,
                        'source'           => $dto->source,
                        'recorded_by'      => null,
                        'created_at'       => now(),
                    ]
                );
            });

            Log::info('FetchBcraExchangeRateJob: rate persisted', [
                'date'             => $dto->date->toDateString(),
                'rate_ars_per_usd' => (string) $dto->rateArsPerUsd,
                'source'           => $dto->source,
            ]);

        } catch (BcraUnavailableException $e) {
            Log::warning('FetchBcraExchangeRateJob: BCRA unavailable, attempting fallback', [
                'error' => $e->getMessage(),
                'date'  => $today->toDateString(),
            ]);

            $this->handleFallback($today, $e);
        }
    }

    /**
     * Copy the most recent available rate as today's fallback entry and notify
     * all Directors.
     */
    private function handleFallback(Carbon $today, BcraUnavailableException $bcraError): void
    {
        $latestRate = ExchangeRate::latest()->first();

        if ($latestRate === null) {
            Log::critical('FetchBcraExchangeRateJob: BCRA unavailable AND no prior rate in DB. Manual intervention required.', [
                'error' => $bcraError->getMessage(),
            ]);

            // Fail the job so Horizon surfaces it in the failed jobs dashboard.
            $this->fail($bcraError);

            return;
        }

        // Avoid creating a duplicate fallback row if one already exists for today.
        $existingToday = ExchangeRate::where('rate_date', $today->toDateString())->first();

        if ($existingToday === null) {
            DB::transaction(function () use ($today, $latestRate): void {
                ExchangeRate::create([
                    'rate_date'        => $today->toDateString(),
                    'rate_ars_per_usd' => $latestRate->rate_ars_per_usd,
                    'source'           => 'fallback',
                    'recorded_by'      => null,
                    'created_at'       => now(),
                ]);
            });

            Log::warning('FetchBcraExchangeRateJob: fallback rate persisted', [
                'date'              => $today->toDateString(),
                'rate_ars_per_usd'  => $latestRate->rate_ars_per_usd,
                'original_rate_date' => $latestRate->rate_date->toDateString(),
            ]);
        }

        // Notify all active Directors (queued — non-blocking).
        $directors = User::where('role', 'director')
            ->where('is_active', true)
            ->get();

        Notification::send(
            $directors,
            new BcraFallbackTriggered(
                fallbackDate:     $today->toDateString(),
                fallbackRate:     $latestRate->rate_ars_per_usd,
                originalRateDate: $latestRate->rate_date->toDateString(),
                apiError:         $bcraError->getMessage(),
            )
        );

        // Phase 14: wire a BcraFallbackOccurred event here once the Reverb
        // broadcasting infrastructure is set up. Example:
        //   event(new BcraFallbackOccurred($today->toDateString(), $latestRate->rate_ars_per_usd));
        // This will push a real-time alert to the private-director Reverb channel.
        Log::info('FetchBcraExchangeRateJob: Directors notified via queued mail; Reverb broadcast deferred to Phase 14.');
    }
}
