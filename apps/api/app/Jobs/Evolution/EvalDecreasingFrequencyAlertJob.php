<?php

declare(strict_types=1);

namespace App\Jobs\Evolution;

use App\Domain\Alerts\Services\AlertDispatcher;
use App\Enums\EvolutionState;
use App\Models\PurchaseEvolutionMetric;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * EvalDecreasingFrequencyAlertJob — Phase 10 (§10.3: "Frecuencia decreciente").
 *
 * Fires when a (customer, product) pair's evolution_state is 'decreasing'.
 * The nightly RecomputePurchaseEvolutionJob updates the state first;
 * this job then scans for decreasing-state rows and alerts the Seller.
 *
 * Alert idempotency (24h window) in AlertDispatcher prevents spam on
 * consecutive days where the state remains decreasing.
 *
 * Recipient: the Seller assigned to the customer.
 * Scheduled: daily at 02:35 ART (after the 02:00 recompute finishes).
 */
class EvalDecreasingFrequencyAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 600];

    public function handle(AlertDispatcher $dispatcher): void
    {
        $metrics = PurchaseEvolutionMetric::with(['customer.assignedSeller'])
            ->where('evolution_state', EvolutionState::Decreasing->value)
            ->get();

        $fired = 0;

        foreach ($metrics as $metric) {
            $customer = $metric->customer;

            if (! $customer || ! $customer->is_active) {
                continue;
            }

            $seller = $customer->assignedSeller;

            if (! $seller instanceof User) {
                continue;
            }

            $dispatcher->dispatch(
                type: 'frequency_decreasing',
                targets: $seller,
                reference: $customer,
                payload: [
                    'product_id'        => $metric->product_id,
                    'avg_interval_days' => $metric->avg_interval_days,
                    'last_interval_days' => $metric->last_interval_days,
                    'pct_over_avg'      => $metric->avg_interval_days > 0
                        ? round((($metric->last_interval_days - $metric->avg_interval_days) / $metric->avg_interval_days) * 100, 1)
                        : null,
                ],
                severity: 'warning',
            );

            $fired++;
        }

        Log::info("EvalDecreasingFrequencyAlertJob: {$fired} alerts dispatched.");
    }
}
