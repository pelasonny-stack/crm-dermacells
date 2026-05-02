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
 * EvalFirstPurchaseNoReorderAlertJob — Phase 10 (§10.3: "Primera compra sin recompra").
 *
 * Fires X days after the first purchase when purchase_count is still 1
 * (no second purchase made). X = config('evolution.first_purchase_no_reorder_days', 30).
 *
 * Trigger condition:
 *   evolution_state = 'first_purchase'
 *   AND last_purchase_date <= now() - X days
 *
 * Recipient: the Seller assigned to the customer.
 * Scheduled: daily at 02:45 ART.
 */
class EvalFirstPurchaseNoReorderAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 600];

    public function handle(AlertDispatcher $dispatcher): void
    {
        $daysThreshold = (int) config('evolution.first_purchase_no_reorder_days', 30);
        $cutoffDate    = now()->subDays($daysThreshold)->toDateString();

        $metrics = PurchaseEvolutionMetric::with(['customer.assignedSeller'])
            ->where('evolution_state', EvolutionState::FirstPurchase->value)
            ->whereNotNull('last_purchase_date')
            ->where('last_purchase_date', '<=', $cutoffDate)
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

            $daysSinceFirstPurchase = (int) now()->startOfDay()->diffInDays($metric->last_purchase_date, false) * -1;

            $dispatcher->dispatch(
                type: 'first_purchase_no_reorder',
                targets: $seller,
                reference: $customer,
                payload: [
                    'product_id'                 => $metric->product_id,
                    'days_since_first_purchase'  => $daysSinceFirstPurchase,
                    'threshold_days'             => $daysThreshold,
                    'first_purchase_date'        => $metric->last_purchase_date?->toDateString(),
                ],
                severity: 'info',
            );

            $fired++;
        }

        Log::info("EvalFirstPurchaseNoReorderAlertJob: {$fired} alerts dispatched.");
    }
}
