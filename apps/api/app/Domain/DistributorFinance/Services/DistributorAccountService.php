<?php

declare(strict_types=1);

namespace App\Domain\DistributorFinance\Services;

use App\Enums\SaleStatus;
use App\Enums\SettlementStatus;
use App\Models\DistributorAccount;
use App\Models\DistributorCommissionPayment;
use App\Models\DistributorSettlement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * DistributorAccountService — §9.3
 *
 * Idempotent service that fully re-aggregates the Distributor's financial
 * position from first principles and writes it to distributor_account.
 *
 * CALCULATION
 * ===========
 *   gross_margin = ventas zona (delivered) − costo preferencial aplicado
 *
 *   balance (saldo a rendir) =
 *       sum(sale.total) for delivered sales in zone
 *       MINUS sum(confirmed settlement amounts)
 *
 *   Commissions paid: separate query on distributor_commission_payments (paid=true).
 *
 * IDEMPOTENCY
 * ===========
 * The job that calls recalculate() is ShouldBeUnique, so concurrent runs are
 * blocked at the queue level. DB::transaction + lockForUpdate() on the account
 * row prevents race conditions if two concurrent calls somehow reach this method.
 *
 * EVENTS THAT TRIGGER RECALCULATION (via listener in Phase 8 listener wiring):
 *   - SaleStatusChanged (to 'delivered' or 'cancelled')
 *   - PaymentRecorded (Phase 7 — lazy guard: skip if payments table absent)
 *   - SettlementConfirmed
 *   - CommissionPaid
 *
 * DEPENDENCIES
 * ============
 * - DistributorMarginService::marginInPeriod() is NOT called here for
 *   performance reasons. Instead, we aggregate at the DB level using
 *   column sums and apply the preferred cost via a scalar subquery.
 *   The margin stored is an approximation based on box quantities × cost;
 *   the DistributorMarginService is authoritative for per-sale display.
 */
final class DistributorAccountService
{
    public function __construct(
        private readonly DistributorMarginService $marginService,
    ) {}

    /**
     * Fully recalculate and persist the distributor_account row.
     *
     * @param User $distributor A user with role=distributor.
     * @return DistributorAccount The updated account row.
     */
    public function recalculate(User $distributor): DistributorAccount
    {
        return DB::transaction(function () use ($distributor): DistributorAccount {
            // Upsert the account row (creates it lazily if not yet existing)
            $account = DistributorAccount::firstOrCreate(
                ['distributor_id' => $distributor->id],
            );

            // Lock the row to prevent concurrent recalculations
            $account = DistributorAccount::where('distributor_id', $distributor->id)
                ->lockForUpdate()
                ->firstOrFail();

            // ------------------------------------------------------------------
            // 1. Delivered sales totals in zone (by currency)
            // ------------------------------------------------------------------
            [$saleArs, $saleUsd] = $this->deliveredSaleTotals($distributor);

            // ------------------------------------------------------------------
            // 2. Confirmed settlements (rendiciones) by currency
            // ------------------------------------------------------------------
            [$settledArs, $settledUsd] = $this->confirmedSettlementTotals($distributor);

            // ------------------------------------------------------------------
            // 3. Preferred cost totals (margin basis)
            // ------------------------------------------------------------------
            [$costArs, $costUsd] = $this->preferredCostTotals($distributor);

            // ------------------------------------------------------------------
            // 4. Compute balances
            // ------------------------------------------------------------------
            // balance = total sales − confirmed settlements already paid
            $balanceArs = bcsub((string) $saleArs, (string) $settledArs, 4);
            $balanceUsd = bcsub((string) $saleUsd, (string) $settledUsd, 4);

            // gross_margin = total sales − cost
            $marginArs = bcsub((string) $saleArs, (string) $costArs, 4);
            $marginUsd = bcsub((string) $saleUsd, (string) $costUsd, 4);

            // ------------------------------------------------------------------
            // 5. Persist
            // ------------------------------------------------------------------
            $account->balance_ars          = $balanceArs;
            $account->balance_usd          = $balanceUsd;
            $account->gross_margin_ars     = $marginArs;
            $account->gross_margin_usd     = $marginUsd;
            $account->last_recalculated_at = now();
            $account->updated_at           = now();
            $account->save();

            return $account->fresh();
        });
    }

    // -------------------------------------------------------------------------
    // Private aggregation helpers
    // -------------------------------------------------------------------------

    /**
     * Returns [totalArs, totalUsd] of all delivered sales in the distributor's zone.
     *
     * @return array{0: float|string, 1: float|string}
     */
    private function deliveredSaleTotals(User $distributor): array
    {
        $rows = DB::table('sales')
            ->join('zones', 'zones.id', '=', 'sales.zone_id')
            ->where('zones.distributor_id', $distributor->id)
            ->where('sales.status', SaleStatus::Delivered->value)
            ->select(
                'sales.total_currency as currency',
                DB::raw('SUM(sales.total_amount) as total'),
            )
            ->groupBy('sales.total_currency')
            ->get()
            ->keyBy('currency');

        return [
            $rows->get('ARS')?->total ?? '0.0000',
            $rows->get('USD')?->total ?? '0.0000',
        ];
    }

    /**
     * Returns [settledArs, settledUsd] of all confirmed settlements.
     *
     * @return array{0: float|string, 1: float|string}
     */
    private function confirmedSettlementTotals(User $distributor): array
    {
        $rows = DistributorSettlement::query()
            ->where('distributor_id', $distributor->id)
            ->where('status', SettlementStatus::Confirmed->value)
            ->select(
                'amount_currency as currency',
                DB::raw('SUM(amount_amount) as total'),
            )
            ->groupBy('amount_currency')
            ->get()
            ->keyBy('currency');

        return [
            $rows->get('ARS')?->total ?? '0.0000',
            $rows->get('USD')?->total ?? '0.0000',
        ];
    }

    /**
     * Returns [costArs, costUsd] — sum of preferred cost × boxes per delivered sale item.
     *
     * We join distributor_preferred_cost to compute the cost-side of the margin.
     * Items without a preferred cost config are treated at base price (zero margin contribution).
     * This is a best-effort aggregation; the DistributorMarginService is the canonical
     * source for per-sale display.
     *
     * @return array{0: string, 1: string}
     */
    private function preferredCostTotals(User $distributor): array
    {
        // We aggregate cost by sale currency (joining to sales for currency info)
        // using COALESCE to fall back to base_price_amount when no preferred cost exists.
        $rows = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('zones', 'zones.id', '=', 'sales.zone_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('distributor_preferred_cost as dpc', function ($join) use ($distributor): void {
                $join->on('dpc.product_id', '=', 'sale_items.product_id')
                    ->where('dpc.distributor_id', $distributor->id);
            })
            ->where('zones.distributor_id', $distributor->id)
            ->where('sales.status', SaleStatus::Delivered->value)
            ->where(function ($q): void {
                // Only include items where the cost currency matches the sale currency
                // to avoid cross-currency arithmetic
                $q->whereRaw("COALESCE(dpc.currency, products.base_price_currency) = sales.total_currency");
            })
            ->select(
                'sales.total_currency as currency',
                DB::raw(
                    'SUM(sale_items.quantity_boxes * CASE '
                    . "WHEN dpc.modality = 'fixed_price' THEN dpc.value "
                    . "WHEN dpc.modality = 'discount_pct' THEN products.base_price_amount * (1 - dpc.value) "
                    . 'ELSE products.base_price_amount '
                    . 'END) as total_cost'
                ),
            )
            ->groupBy('sales.total_currency')
            ->get()
            ->keyBy('currency');

        return [
            (string) ($rows->get('ARS')?->total_cost ?? '0.0000'),
            (string) ($rows->get('USD')?->total_cost ?? '0.0000'),
        ];
    }

    /**
     * Guard for Phase 7 lazy dependency: checks if the payments table exists
     * before querying it. Returns true if the table is present.
     */
    private function paymentsTableExists(): bool
    {
        try {
            DB::table('payments')->limit(1)->get();
            return true;
        } catch (\Illuminate\Database\QueryException) {
            return false;
        }
    }
}
