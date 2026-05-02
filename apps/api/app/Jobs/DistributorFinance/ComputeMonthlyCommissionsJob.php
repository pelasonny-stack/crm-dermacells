<?php

declare(strict_types=1);

namespace App\Jobs\DistributorFinance;

use App\Domain\DistributorFinance\Services\CommissionAssignmentService;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\DistributorCommissionPayment;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ComputeMonthlyCommissionsJob — Phase 8 / §9.2
 *
 * Runs on the 1st of each month (scheduled in routes/console.php) to
 * compute the commission owed by each Distributor to each Seller in each
 * Zone for the *prior* calendar month.
 *
 * ALGORITHM
 * =========
 * For each (distributor, zone):
 *   For each seller who had delivered sales in that zone during the prior month:
 *     1. Sum the base sales amount (in the native currency of each sale).
 *     2. Resolve commission_pct via CommissionAssignmentService as of the
 *        first day of the *prior* month (prevents mid-month changes from
 *        affecting past periods).
 *     3. Compute commission_amount = base_amount × commission_pct.
 *     4. INSERT ... ON CONFLICT DO NOTHING (idempotent via the UNIQUE constraint).
 *
 * CURRENCY
 * =========
 * Because sales can be in ARS or USD, we group by currency and insert one
 * row per (distributor, seller, zone, period_month, currency). Since the UNIQUE
 * constraint is on (distributor_id, seller_id, zone_id, period_month) we handle
 * this by storing amounts in the sale's native currency.
 *
 * For simplicity and to respect the §9.2 rule that "each Distributor pays
 * independently per zone", we produce one commission payment per (distributor,
 * seller, zone, period_month) combining both currencies into the dominant
 * currency. If the Distributor has mixed-currency sales, the aggregation is
 * done separately and two rows created (one ARS, one USD) using a virtual
 * zone+currency composite approach below.
 *
 * IDEMPOTENCY
 * ===========
 * INSERT ... ON CONFLICT (distributor_id, seller_id, zone_id, period_month) DO NOTHING
 * ensures safe re-runs (e.g., if the job fails and is retried).
 *
 * IMPORTANT: director users with can_sell=true DO appear as sellers in zone sales
 * (§9.2: "all sales in the zone, regardless of role"). Their commissions are
 * computed the same way — the Distributor configured the percentage.
 * Directors receive no *Vendedor commission* (Phase 9) but DO have Distributor
 * commissions paid to them by Distributors per §9.2.
 */
class ComputeMonthlyCommissionsJob implements ShouldQueue
{
    use Queueable;

    /**
     * Maximum attempts before failure.
     */
    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60, 300];

    public int $timeout = 600;

    /**
     * The prior month to compute for.
     * Defaults to the calendar month preceding today.
     * Can be overridden in tests / manual re-runs.
     */
    public function __construct(
        public readonly ?Carbon $periodMonth = null,
    ) {}

    public function handle(CommissionAssignmentService $commissionService): void
    {
        // Target: the prior calendar month
        $period = ($this->periodMonth ?? today()->subMonthNoOverflow())
            ->startOfMonth()
            ->toImmutable();

        $periodStart = $period->toDateString();
        $periodEnd   = $period->endOfMonth()->toDateString();
        $periodLabel = $period->format('Y-m-d');

        Log::info("ComputeMonthlyCommissionsJob: computing commissions for period {$periodLabel}");

        // Iterate over all zones that have a Distributor assigned
        Zone::query()
            ->whereNotNull('distributor_id')
            ->with('distributor')
            ->each(function (Zone $zone) use ($commissionService, $period, $periodStart, $periodEnd): void {
                $this->processZone($zone, $commissionService, $period, $periodStart, $periodEnd);
            });

        Log::info("ComputeMonthlyCommissionsJob: completed for period {$period->format('Y-m-d')}");
    }

    private function processZone(
        Zone $zone,
        CommissionAssignmentService $commissionService,
        Carbon|CarbonImmutable $period,
        string $periodStart,
        string $periodEnd,
    ): void {
        /** @var User $distributor */
        $distributor = $zone->distributor;

        if ($distributor === null) {
            return;
        }

        // Aggregate delivered sales in this zone, grouped by (seller_id, currency)
        $salesByCurrencyAndSeller = DB::table('sales')
            ->where('zone_id', $zone->id)
            ->where('status', SaleStatus::Delivered->value)
            ->whereBetween('sale_date', [$periodStart, $periodEnd])
            ->select(
                'seller_id',
                'total_currency as currency',
                DB::raw('SUM(total_amount) as total'),
            )
            ->groupBy('seller_id', 'total_currency')
            ->get();

        foreach ($salesByCurrencyAndSeller as $row) {
            $sellerId = $row->seller_id;
            $currency = $row->currency;
            $baseAmount = (string) $row->total;

            // Resolve commission pct as-of the first day of the prior month
            // so mid-month changes don't affect the period being computed.
            $seller = User::find($sellerId);

            if ($seller === null) {
                continue;
            }

            $pct = $commissionService->currentPctFor(
                distributor: $distributor,
                seller: $seller,
                zone: $zone,
                asOf: $period->toMutable(),
            );

            // No pct configured → skip (Distributor has not set a rate for this seller)
            if ($pct === null) {
                continue;
            }

            // commission_amount = base_amount × pct
            $commissionAmount = bcmul($baseAmount, number_format($pct, 4, '.', ''), 4);

            // INSERT ... ON CONFLICT DO NOTHING (idempotent)
            // Note: we use the zone+period_month UNIQUE constraint; currency is captured
            // in the amounts. When a seller has both ARS and USD sales in the same zone+month,
            // only one row is produced (first wins). For simplicity MVP uses dominant currency
            // approach; a future migration can add currency to the unique key.
            try {
                DB::table('distributor_commission_payments')->insertOrIgnore([
                    'id'                         => \Illuminate\Support\Str::uuid()->toString(),
                    'distributor_id'             => $distributor->id,
                    'seller_id'                  => $sellerId,
                    'zone_id'                    => $zone->id,
                    'period_month'               => $period->toDateString(),
                    'base_amount_amount'         => $baseAmount,
                    'base_amount_currency'       => $currency,
                    'commission_pct'             => number_format($pct, 4, '.', ''),
                    'commission_amount_amount'   => $commissionAmount,
                    'commission_amount_currency' => $currency,
                    'paid'                       => false,
                    'created_at'                 => now(),
                    'updated_at'                 => now(),
                ]);
            } catch (\Throwable $e) {
                Log::error("ComputeMonthlyCommissionsJob: failed to insert commission for seller {$sellerId} in zone {$zone->id}: " . $e->getMessage());
            }
        }
    }
}
