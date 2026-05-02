<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Queries;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ranks Distribuidores by their zone's total USD-equivalent volume in the
 * current month.
 *
 * Uses raw DB facade per PLAN.md aggregation decision.
 */
final class RankingDistributorsQuery
{
    /**
     * @return Collection<int, object{distributor_id:string, distributor_name:string, zone_id:string, zone_name:string, volume_ars:string, volume_usd:string, usd_equivalent:string, rank:int}>
     */
    public function get(): Collection
    {
        $start = now()->startOfMonth()->toDateString();
        $end   = now()->endOfMonth()->toDateString();

        return DB::table('payments')
            ->join('sales', 'sales.id', '=', 'payments.sale_id')
            ->join('zones', 'zones.id', '=', 'sales.zone_id')
            ->join('users', 'users.id', '=', 'zones.distributor_id')
            ->join('exchange_rates', 'exchange_rates.id', '=', 'payments.exchange_rate_id')
            ->whereNotNull('zones.distributor_id')
            ->whereBetween('payments.payment_date', [$start, $end])
            ->where('payments.reversed', false)
            ->selectRaw('
                zones.distributor_id,
                users.full_name AS distributor_name,
                zones.id AS zone_id,
                zones.name AS zone_name,
                SUM(CASE WHEN payments.amount_currency = \'ARS\' THEN payments.amount_amount ELSE 0 END) AS volume_ars,
                SUM(CASE WHEN payments.amount_currency = \'USD\' THEN payments.amount_amount ELSE 0 END) AS volume_usd,
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
            ->groupBy('zones.distributor_id', 'users.full_name', 'zones.id', 'zones.name')
            ->orderByDesc('usd_equivalent')
            ->get();
    }
}
