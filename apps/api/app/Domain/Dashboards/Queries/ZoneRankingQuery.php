<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Queries;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ranks Vendedores within a zone by USD-equivalent collected in the current month.
 *
 * ARS cobros are converted to USD-equivalent using the exchange rate stored
 * on each payment row (same algorithm as CommissionCalculatorService).
 *
 * Uses raw DB facade per PLAN.md aggregation decision.
 */
final class ZoneRankingQuery
{
    /**
     * Ranking for a specific distributor's zone(s).
     *
     * @return Collection<int, object{seller_id:string, seller_name:string, zone_id:string, collected_ars:string, collected_usd:string, usd_equivalent:string, rank:int}>
     */
    public function forDistributor(string $distributorId): Collection
    {
        $start = now()->startOfMonth()->toDateString();
        $end   = now()->endOfMonth()->toDateString();

        return DB::table('payments')
            ->join('sales', 'sales.id', '=', 'payments.sale_id')
            ->join('users', 'users.id', '=', 'sales.seller_id')
            ->join('zones', 'zones.id', '=', 'sales.zone_id')
            ->join('exchange_rates', 'exchange_rates.id', '=', 'payments.exchange_rate_id')
            ->where('zones.distributor_id', $distributorId)
            ->whereBetween('payments.payment_date', [$start, $end])
            ->where('payments.reversed', false)
            ->selectRaw('
                sales.seller_id,
                users.full_name AS seller_name,
                sales.zone_id,
                SUM(CASE WHEN payments.amount_currency = \'ARS\' THEN payments.amount_amount ELSE 0 END) AS collected_ars,
                SUM(CASE WHEN payments.amount_currency = \'USD\' THEN payments.amount_amount ELSE 0 END) AS collected_usd,
                SUM(
                    CASE
                        WHEN payments.amount_currency = \'ARS\'
                        THEN payments.amount_amount / NULLIF(exchange_rates.rate_ars_per_usd, 0)
                        ELSE payments.amount_amount
                    END
                ) AS usd_equivalent
            ')
            ->groupBy('sales.seller_id', 'users.full_name', 'sales.zone_id')
            ->orderByDesc('usd_equivalent')
            ->get();
    }

    /**
     * Global seller ranking across all zones (Director view).
     *
     * @return Collection<int, object>
     */
    public function global(): Collection
    {
        $start = now()->startOfMonth()->toDateString();
        $end   = now()->endOfMonth()->toDateString();

        return DB::table('payments')
            ->join('sales', 'sales.id', '=', 'payments.sale_id')
            ->join('users', 'users.id', '=', 'sales.seller_id')
            ->join('exchange_rates', 'exchange_rates.id', '=', 'payments.exchange_rate_id')
            ->whereBetween('payments.payment_date', [$start, $end])
            ->where('payments.reversed', false)
            ->selectRaw('
                sales.seller_id,
                users.full_name AS seller_name,
                SUM(CASE WHEN payments.amount_currency = \'ARS\' THEN payments.amount_amount ELSE 0 END) AS collected_ars,
                SUM(CASE WHEN payments.amount_currency = \'USD\' THEN payments.amount_amount ELSE 0 END) AS collected_usd,
                SUM(
                    CASE
                        WHEN payments.amount_currency = \'ARS\'
                        THEN payments.amount_amount / NULLIF(exchange_rates.rate_ars_per_usd, 0)
                        ELSE payments.amount_amount
                    END
                ) AS usd_equivalent,
                RANK() OVER (ORDER BY SUM(
                    CASE
                        WHEN payments.amount_currency = \'ARS\'
                        THEN payments.amount_amount / NULLIF(exchange_rates.rate_ars_per_usd, 0)
                        ELSE payments.amount_amount
                    END
                ) DESC) AS rank
            ')
            ->groupBy('sales.seller_id', 'users.full_name')
            ->orderByDesc('usd_equivalent')
            ->get();
    }
}
