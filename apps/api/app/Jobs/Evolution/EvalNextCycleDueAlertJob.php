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
 * EvalNextCycleDueAlertJob — Phase 10 (§10.3: "Próximo a vencer ciclo").
 *
 * Fires X days before the expected next purchase date for each active
 * (customer, product) pair where a frequency expectation exists.
 *
 * Trigger condition:
 *   days_since_last_purchase >= (frequency_expected - alert_days_before)
 *   AND days_since_last_purchase < frequency_expected
 *
 * i.e.: the window starts EVO_NEXT_CYCLE_DAYS days before the expected date
 * and ends the day before the customer goes inactive.
 *
 * Recipient: the Seller assigned to the customer.
 * Scheduled: daily at 02:30 ART.
 */
class EvalNextCycleDueAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 600];

    public function handle(AlertDispatcher $dispatcher): void
    {
        $daysBefore = (int) config('evolution.next_cycle_alert_days_before', 5);

        // Fetch all non-first-purchase metrics with a known last_purchase_date
        $metrics = PurchaseEvolutionMetric::with(['customer.assignedSeller', 'customer.category'])
            ->whereNotIn('evolution_state', [EvolutionState::FirstPurchase->value, EvolutionState::Inactive->value])
            ->whereNotNull('last_purchase_date')
            ->get();

        $fired = 0;

        foreach ($metrics as $metric) {
            $customer = $metric->customer;

            if (! $customer || ! $customer->is_active) {
                continue;
            }

            $frequencyDays = $customer->purchase_frequency_days
                ?? ($customer->category->default_purchase_frequency_days ?? 30);

            $daysSinceLast = (int) now()->startOfDay()->diffInDays($metric->last_purchase_date, false) * -1;

            $windowStart = $frequencyDays - $daysBefore;

            if ($daysSinceLast >= $windowStart && $daysSinceLast < $frequencyDays) {
                $seller = $customer->assignedSeller;

                if (! $seller instanceof User) {
                    continue;
                }

                $dispatcher->dispatch(
                    type: 'cycle_due_soon',
                    targets: $seller,
                    reference: $customer,
                    payload: [
                        'product_id'       => $metric->product_id,
                        'days_since_last'  => $daysSinceLast,
                        'frequency_days'   => $frequencyDays,
                        'days_until_due'   => $frequencyDays - $daysSinceLast,
                    ],
                    severity: 'info',
                );

                $fired++;
            }
        }

        Log::info("EvalNextCycleDueAlertJob: {$fired} alerts dispatched.");
    }
}
