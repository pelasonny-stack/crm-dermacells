<?php

declare(strict_types=1);

namespace App\Jobs\Evolution;

use App\Domain\Alerts\Services\AlertDispatcher;
use App\Enums\EvolutionState;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\PurchaseEvolutionMetric;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * EvalZoneAtRiskAlertJob — Phase 10 (§10.3: "Zona en riesgo").
 *
 * Daily; per zone: computes what % of active customers in that zone have at
 * least one product in 'inactive' state. If that percentage exceeds the
 * configured threshold (EVO_ZONE_RISK_PCT, default 30%), fires an alert
 * to the Distributor of the zone + ALL Directors.
 *
 * "Zone at risk" is evaluated at the customer level (not metric level):
 *   - A customer counts as "inactive" if ANY of their product metrics is inactive.
 *   - Denominator = all active customers in the zone.
 *
 * Recipient: Distributor (if zone has one) + all Directors.
 * Reference entity: Zone.
 * Scheduled: daily at 03:00 ART.
 */
class EvalZoneAtRiskAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 600];

    public function handle(AlertDispatcher $dispatcher): void
    {
        $thresholdPct = (float) config('evolution.zone_inactive_threshold_pct', 30);

        $zones = Zone::all();

        $directors = User::where('role', UserRole::Director->value)
            ->where('is_active', true)
            ->get()
            ->all();

        $fired = 0;

        foreach ($zones as $zone) {
            $activeCustomerIds = Customer::where('zone_id', $zone->id)
                ->where('is_active', true)
                ->pluck('id');

            $totalCustomers = $activeCustomerIds->count();

            if ($totalCustomers === 0) {
                continue;
            }

            // Customers with at least one inactive metric
            $inactiveCustomerIds = PurchaseEvolutionMetric::whereIn('customer_id', $activeCustomerIds)
                ->where('evolution_state', EvolutionState::Inactive->value)
                ->distinct()
                ->pluck('customer_id');

            $inactiveCount = $inactiveCustomerIds->unique()->count();
            $inactivePct   = ($inactiveCount / $totalCustomers) * 100;

            if ($inactivePct < $thresholdPct) {
                continue;
            }

            $targets = $directors;

            if (! empty($zone->distributor_id)) {
                $distributor = User::where('id', $zone->distributor_id)
                    ->where('role', UserRole::Distributor->value)
                    ->where('is_active', true)
                    ->first();

                if ($distributor) {
                    $targets[] = $distributor;
                }
            }

            $dispatcher->dispatch(
                type: 'zone_at_risk',
                targets: $targets,
                reference: $zone,
                payload: [
                    'zone_id'         => $zone->id,
                    'zone_name'       => $zone->name ?? $zone->id,
                    'total_customers' => $totalCustomers,
                    'inactive_count'  => $inactiveCount,
                    'inactive_pct'    => round($inactivePct, 1),
                    'threshold_pct'   => $thresholdPct,
                ],
                severity: 'critical',
            );

            $fired++;
        }

        Log::info("EvalZoneAtRiskAlertJob: {$fired} zone-risk alerts dispatched.");
    }
}
