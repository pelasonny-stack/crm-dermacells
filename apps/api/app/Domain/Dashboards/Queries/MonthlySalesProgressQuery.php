<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Queries;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates delivered sales for a seller in a given month.
 *
 * Returns counts and amounts for the target month and the immediately
 * preceding month so dashboards can display the comparison.
 *
 * Uses raw DB facade per PLAN.md aggregation decision.
 */
final class MonthlySalesProgressQuery
{
    /**
     * @return array{count:int, amount:string, currency:string, boxes:int}
     */
    public function forSeller(string $sellerId, Carbon $month): array
    {
        $start = $month->copy()->startOfMonth()->toDateString();
        $end   = $month->copy()->endOfMonth()->toDateString();

        $row = DB::table('sales')
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.seller_id', $sellerId)
            ->where('sales.status', 'delivered')
            ->whereBetween('sales.sale_date', [$start, $end])
            ->selectRaw('
                COUNT(DISTINCT sales.id) AS sale_count,
                SUM(sales.total_amount)  AS total_amount,
                MAX(sales.currency)      AS currency,
                SUM(sale_items.quantity_boxes)    AS total_boxes
            ')
            ->first();

        return [
            'count'    => (int) ($row->sale_count ?? 0),
            'amount'   => number_format((float) ($row->total_amount ?? 0), 4, '.', ''),
            'currency' => (string) ($row->currency ?? 'ARS'),
            'boxes'    => (int) ($row->total_boxes ?? 0),
        ];
    }

    /**
     * Aggregation for the Director: all sellers in a given month.
     *
     * @return array{count:int, boxes:int, amount:string}
     */
    public function global(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth()->toDateString();
        $end   = $month->copy()->endOfMonth()->toDateString();

        $row = DB::table('sales')
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.status', 'delivered')
            ->whereBetween('sales.sale_date', [$start, $end])
            ->selectRaw('
                COUNT(DISTINCT sales.id) AS sale_count,
                SUM(sale_items.quantity_boxes)    AS total_boxes,
                SUM(sales.total_amount)  AS total_amount
            ')
            ->first();

        return [
            'count'  => (int) ($row->sale_count ?? 0),
            'boxes'  => (int) ($row->total_boxes ?? 0),
            'amount' => number_format((float) ($row->total_amount ?? 0), 4, '.', ''),
        ];
    }
}
