<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Queries;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Returns sales whose due_date falls within the next X hours that still
 * have outstanding (unpaid) balances.
 *
 * "Unpaid" means: the sale's total_amount exceeds the sum of non-reversed
 * payments against it.  We compute this in SQL to avoid N+1 in PHP.
 *
 * RLS: enforced at the Postgres layer via app.user_id / app.user_role GUCs
 * set by SetPostgresRlsContext middleware.
 */
final class DuePaymentsWithinHoursQuery
{
    public function get(string $sellerId, int $hours = 48): Collection
    {
        $cutoff = now()->addHours($hours);
        $now    = now();

        return DB::table('sales')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->whereIn('sales.status', ['confirmed', 'delivered'])
            ->where('sales.seller_id', $sellerId)
            ->whereBetween('sales.due_date', [
                $now->toDateTimeString(),
                $cutoff->toDateTimeString(),
            ])
            ->whereRaw(
                'sales.total_amount > COALESCE((
                    SELECT SUM(p.amount_amount)
                    FROM payments p
                    WHERE p.sale_id = sales.id
                      AND p.reversed = false
                      AND p.amount_currency = sales.currency
                ), 0)'
            )
            ->orderBy('sales.due_date', 'asc')
            ->get([
                'sales.id',
                'sales.due_date',
                'sales.total_amount',
                'sales.currency',
                'customers.id as customer_id',
                'customers.first_name',
                'customers.last_name',
            ]);
    }
}
