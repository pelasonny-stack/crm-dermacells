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
use Illuminate\Support\Facades\Log;

/**
 * EvalCustomerInactiveAlertJob — Phase 10 (§10.3: "Cliente inactivo").
 *
 * Fires when a (customer, product) pair transitions to 'inactive' state.
 * Both the assigned Seller AND the Distributor of the customer's zone
 * are notified (per §10.3 destinatario column).
 *
 * Idempotency in AlertDispatcher prevents per-target duplicates within 24h
 * while the customer remains inactive on consecutive days.
 *
 * Recipients: Seller assigned to customer + Distributor of customer's zone
 *             (if zone has a distributor_id set).
 * Scheduled: daily at 02:40 ART.
 */
class EvalCustomerInactiveAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 600];

    public function handle(AlertDispatcher $dispatcher): void
    {
        $metrics = PurchaseEvolutionMetric::with(['customer.assignedSeller', 'customer.zone'])
            ->where('evolution_state', EvolutionState::Inactive->value)
            ->get();

        $fired = 0;

        foreach ($metrics as $metric) {
            $customer = $metric->customer;

            if (! $customer || ! $customer->is_active) {
                continue;
            }

            $targets = [];

            if ($customer->assignedSeller instanceof User) {
                $targets[] = $customer->assignedSeller;
            }

            // Add Distributor if the zone has one assigned
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

            $daysSinceLast = $metric->last_purchase_date
                ? (int) now()->startOfDay()->diffInDays($metric->last_purchase_date, false) * -1
                : null;

            $dispatcher->dispatch(
                type: 'customer_inactive',
                targets: $targets,
                reference: $customer,
                payload: [
                    'product_id'       => $metric->product_id,
                    'days_since_last'  => $daysSinceLast,
                    'avg_interval_days' => $metric->avg_interval_days,
                    'purchase_count'   => $metric->purchase_count,
                ],
                severity: 'warning',
            );

            $fired++;
        }

        Log::info("EvalCustomerInactiveAlertJob: {$fired} pairs processed.");
    }
}
