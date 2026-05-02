<?php

declare(strict_types=1);

namespace App\Jobs\Evolution;

use App\Domain\Alerts\Services\AlertDispatcher;
use App\Enums\EvolutionState;
use App\Enums\UserRole;
use App\Models\PurchaseEvolutionMetric;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * EvalCustomerRecoveredAlertJob — Phase 10 (§10.3: "Recuperación de cliente").
 *
 * Fires when a customer that was previously 'inactive' transitions to
 * first_purchase, stable, or increasing state — meaning they bought again.
 *
 * TRANSITION DETECTION STRATEGY
 * ==============================
 * Postgres does not store prior state; we detect the transition by keeping a
 * Redis cache of the previous night's states (written by this job, keyed per
 * (customer_id, product_id)). On each run:
 *   - Load current metrics in non-inactive states.
 *   - Check if the prior-state cache for that pair was 'inactive'.
 *   - If yes → recovered transition → fire alert.
 *   - Always write current state to cache for the next run.
 *
 * Cache key: "evo_prev_state:{customer_id}:{product_id}" → state string, TTL 48h.
 *
 * Recipients: Seller + Distributor (same as inactive alert).
 * Note: this job is NOT in the scheduled list in the PLAN — it runs as part of
 * the same nightly batch (called explicitly at 02:40 after the inactive job).
 * For scheduling parity it should be added or merged with the inactive job;
 * here we implement it as a standalone job ready to be scheduled separately.
 */
class EvalCustomerRecoveredAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 600];

    private const CACHE_TTL_HOURS = 48;

    public function handle(AlertDispatcher $dispatcher): void
    {
        // Load ALL current metrics to detect state transitions
        $metrics = PurchaseEvolutionMetric::with(['customer.assignedSeller', 'customer.zone'])
            ->get();

        $fired = 0;

        foreach ($metrics as $metric) {
            $cacheKey    = "evo_prev_state:{$metric->customer_id}:{$metric->product_id}";
            $previousState = Cache::get($cacheKey);
            $currentState  = $metric->evolution_state->value;

            // Write current state for next run BEFORE checking recovery
            Cache::put($cacheKey, $currentState, now()->addHours(self::CACHE_TTL_HOURS));

            // Recovery = was inactive, now not inactive and not first_purchase
            if ($previousState !== EvolutionState::Inactive->value) {
                continue;
            }

            if (in_array($currentState, [EvolutionState::Inactive->value, EvolutionState::FirstPurchase->value], true)) {
                continue;
            }

            $customer = $metric->customer;

            if (! $customer || ! $customer->is_active) {
                continue;
            }

            $targets = [];

            if ($customer->assignedSeller instanceof User) {
                $targets[] = $customer->assignedSeller;
            }

            $zone = $customer->zone;

            if ($zone && ! empty($zone->distributor_id)) {
                $distributor = User::where('id', $zone->distributor_id)
                    ->where('role', UserRole::Distributor->value)
                    ->where('is_active', true)
                    ->first();

                if ($distributor) {
                    $targets[] = $distributor;
                }
            }

            if (empty($targets)) {
                continue;
            }

            $dispatcher->dispatch(
                type: 'customer_recovered',
                targets: $targets,
                reference: $customer,
                payload: [
                    'product_id'        => $metric->product_id,
                    'previous_state'    => $previousState,
                    'current_state'     => $currentState,
                    'purchase_count'    => $metric->purchase_count,
                ],
                severity: 'info',
            );

            $fired++;
        }

        Log::info("EvalCustomerRecoveredAlertJob: {$fired} recovery alerts dispatched.");
    }
}
